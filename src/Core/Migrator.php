<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Applies migrations/NNN_name.sql files in name order, each once.
 * Placeholder {AUTOID} expands to the driver's auto-increment primary key.
 *
 * Safety: on SQLite each file runs in one transaction (DDL is transactional), so a
 * failure leaves nothing behind. MySQL DDL cannot be rolled back, so there run() holds
 * a GET_LOCK against concurrent runs, and migrations must be small and re-runnable:
 * use CREATE TABLE IF NOT EXISTS; CREATE [UNIQUE] INDEX is skipped on MySQL when the
 * index already exists.
 */
final class Migrator
{
    public function __construct(private Db $db, private string $dir)
    {
    }

    /** @return int number of migrations applied in this run */
    public function run(): int
    {
        if ($this->db->driver() !== 'mysql') {
            return $this->apply();
        }
        $got = $this->db->one("SELECT GET_LOCK('faktura_migrate', 5) AS l");
        if ((int) ($got['l'] ?? 0) !== 1) {
            throw new \RuntimeException('Could not obtain migration lock');
        }
        try {
            return $this->apply();
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('faktura_migrate') AS l");
        }
    }

    private function apply(): int
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY)');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');

        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException("Cannot read migration $name");
            }
            $stmts = $this->statements($raw);
            if ($raw !== '' && $stmts === []) {
                throw new \RuntimeException("Migration $name contains no statements");
            }
            $apply = function () use ($stmts, $name): void {
                foreach ($stmts as $stmt) {
                    $this->execute($stmt);
                }
                $this->db->exec('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            };
            if ($this->db->driver() === 'sqlite') {
                $this->db->transaction($apply);
            } else {
                $apply();
            }
            $applied++;
        }
        return $applied;
    }

    private function execute(string $stmt): void
    {
        if ($this->db->driver() === 'mysql'
            && preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $stmt, $m)
            && $this->db->one(
                'SELECT 1 AS x FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$m[2], $m[1]]
            ) !== null
        ) {
            return;
        }
        $this->db->exec($stmt);
    }

    /**
     * Naive splitter. Limits: a ';' at end of a line inside a string literal splits the
     * statement, and a line starting with '--' inside a multi-line literal is stripped.
     * Keep migrations free of such literals.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $auto = $this->db->driver() === 'mysql'
            ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = str_replace('{AUTOID}', $auto, $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $out = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
