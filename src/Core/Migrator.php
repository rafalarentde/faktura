<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Applies migrations/NNN_name.sql files in name order, each once.
 * Placeholder {AUTOID} expands to the driver's auto-increment primary key.
 * Statements are separated by ';' at end of line; '--' comment lines are ignored.
 */
final class Migrator
{
    public function __construct(private Db $db, private string $dir)
    {
    }

    /** @return int number of migrations applied in this run */
    public function run(): int
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
            foreach ($this->statements((string) file_get_contents($file)) as $stmt) {
                $this->db->exec($stmt);
            }
            $this->db->exec('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $applied++;
        }
        return $applied;
    }

    /** @return list<string> */
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
