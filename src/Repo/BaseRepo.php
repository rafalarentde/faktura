<?php
declare(strict_types=1);

namespace App\Repo;

use App\Core\Db;
use App\Tenant\CompanyContext;

/**
 * Base of every business repository. All access to tenant tables goes through the scoped*
 * helpers, which put `company_id = <context company>` into every statement. Table and column
 * names must be literals chosen by the code, never user input; they are validated anyway.
 * $where fragments use positional (?) parameters only.
 */
abstract class BaseRepo
{
    public function __construct(protected Db $db, protected CompanyContext $ctx)
    {
    }

    /** @return array<string, mixed>|null */
    protected function scopedOne(string $table, int $id): ?array
    {
        $t = $this->ident($table);
        return $this->db->one("SELECT * FROM `$t` WHERE company_id = ? AND id = ?", [$this->ctx->id(), $id]);
    }

    /**
     * $where MUST be a literal written in code; user values go only into ? params. It is
     * wrapped in parentheses and ANDed with the tenant filter, and rejected when it could
     * break out of them (unbalanced parentheses or quotes; any of ; # [ ] { } backslash NUL --
     * /* ?<digit> UNION: [..] and {..} are SQLite/MySQL quoting and escape syntax the checker
     * does not model).
     * $orderBy is a list like "name ASC, id DESC". $limit/$offset are integers.
     *
     * @param list<scalar|null> $params positional parameters for $where
     * @return list<array<string, mixed>>
     */
    protected function scopedAll(
        string $table,
        string $where = '1=1',
        array $params = [],
        string $orderBy = '',
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $t = $this->ident($table);
        $this->checkWhere($where);
        $sql = "SELECT * FROM `$t` WHERE company_id = ? AND ($where)";
        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $this->orderBy($orderBy);
        }
        if ($limit !== null) {
            if ($limit < 0 || $offset < 0) {
                throw new \InvalidArgumentException('Invalid LIMIT/OFFSET.');
            }
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        } elseif ($offset !== 0) {
            throw new \InvalidArgumentException('OFFSET requires LIMIT.');
        }
        return $this->db->all($sql, [$this->ctx->id(), ...array_values($params)]);
    }

    /**
     * Number of rows of the context company matching $where (same rules as scopedAll).
     *
     * @param list<scalar|null> $params positional parameters for $where
     */
    protected function scopedCount(string $table, string $where = '1=1', array $params = []): int
    {
        $t = $this->ident($table);
        $this->checkWhere($where);
        $row = $this->db->one(
            "SELECT COUNT(*) AS c FROM `$t` WHERE company_id = ? AND ($where)",
            [$this->ctx->id(), ...array_values($params)]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** Inserts $row with company_id forced to the context company; returns the new id. */
    protected function scopedInsert(string $table, array $row): int
    {
        $t = $this->ident($table);
        unset($row['company_id']);
        if ($row === []) {
            throw new \InvalidArgumentException('Empty row.');
        }
        $row = ['company_id' => $this->ctx->id()] + $row;
        $cols = array_map(fn ($c) => '`' . $this->ident((string) $c) . '`', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $this->db->exec("INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES ($marks)", array_values($row));
        return $this->db->lastId();
    }

    /**
     * Atomically adds $by to an integer column of a row of the context company in ONE statement
     * (`SET col = col + ?`), so concurrent callers can never read-modify-write the same value.
     * With $max the statement also requires `col + $by <= $max`, so a counter can never pass its
     * limit (the check and the write are the same statement). Returns affected rows (0 when the
     * row is not the company's or the limit would be passed). The caller reads the new value back
     * inside the same transaction.
     */
    protected function scopedIncrement(string $table, int $id, string $column, int $by = 1, ?int $max = null): int
    {
        $t = $this->ident($table);
        $c = $this->ident($column);
        if ($c === 'id' || $c === 'company_id') {
            throw new \InvalidArgumentException('Column cannot be incremented.');
        }
        $sql = "UPDATE `$t` SET `$c` = `$c` + ? WHERE company_id = ? AND id = ?";
        $params = [$by, $this->ctx->id(), $id];
        if ($max !== null) {
            $sql .= " AND `$c` <= ?";
            $params[] = $max - $by;
        }
        return $this->db->exec($sql, $params);
    }

    /**
     * Monotonic set: `SET col = $value WHERE ... AND col < $value`. The value can only go UP, and
     * the comparison happens in the statement itself, so a concurrent raise or increment is never
     * overwritten with a lower value. Returns affected rows (0 = the column was already >= $value).
     */
    protected function scopedRaise(string $table, int $id, string $column, int $value): int
    {
        $t = $this->ident($table);
        $c = $this->ident($column);
        if ($c === 'id' || $c === 'company_id') {
            throw new \InvalidArgumentException('Column cannot be raised.');
        }
        return $this->db->exec(
            "UPDATE `$t` SET `$c` = ? WHERE company_id = ? AND id = ? AND `$c` < ?",
            [$value, $this->ctx->id(), $id, $value]
        );
    }

    /** Updates a row of the context company; company_id in $row is ignored. Returns affected rows. */
    protected function scopedUpdate(string $table, int $id, array $row): int
    {
        $t = $this->ident($table);
        unset($row['company_id']);
        if (array_key_exists('id', $row)) {
            throw new \InvalidArgumentException('The id column cannot be updated.');
        }
        $sets = array_map(fn ($c) => '`' . $this->ident((string) $c) . '` = ?', array_keys($row));
        if ($sets === []) {
            return 0;
        }
        return $this->db->exec(
            "UPDATE `$t` SET " . implode(', ', $sets) . ' WHERE company_id = ? AND id = ?',
            [...array_values($row), $this->ctx->id(), $id]
        );
    }

    protected function scopedDelete(string $table, int $id): int
    {
        $t = $this->ident($table);
        return $this->db->exec("DELETE FROM `$t` WHERE company_id = ? AND id = ?", [$this->ctx->id(), $id]);
    }

    private function ident(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL identifier.');
        }
        return $name;
    }

    private function checkWhere(string $where): void
    {
        if (preg_match('/[;#\\[\\]{}\\\\\\x00]|--|\/\*|\?\d|\bUNION\b/i', $where) === 1) {
            throw new \InvalidArgumentException('Forbidden token in WHERE.');
        }
        $depth = 0;
        $quote = null;
        foreach (str_split($where) as $ch) {
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null; // a doubled quote simply reopens on the next char
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && --$depth < 0) {
                throw new \InvalidArgumentException('Unbalanced parentheses in WHERE.');
            }
        }
        if ($depth !== 0 || $quote !== null) {
            throw new \InvalidArgumentException('Unbalanced WHERE.');
        }
    }

    private function orderBy(string $orderBy): string
    {
        $parts = [];
        foreach (explode(',', $orderBy) as $part) {
            if (preg_match('/^\s*([a-z_][a-z0-9_]*)(?:\s+(ASC|DESC))?\s*\\z/i', $part, $m) !== 1) {
                throw new \InvalidArgumentException('Invalid ORDER BY.');
            }
            $parts[] = '`' . $m[1] . '`' . (isset($m[2]) ? ' ' . strtoupper($m[2]) : '');
        }
        return implode(', ', $parts);
    }
}
