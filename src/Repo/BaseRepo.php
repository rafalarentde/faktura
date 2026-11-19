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
     * $where is wrapped in parentheses and ANDed with the tenant filter, so an OR inside it
     * cannot reach other companies. $orderBy is a list like "name ASC, id DESC".
     *
     * @param list<scalar|null> $params positional parameters for $where
     * @return list<array<string, mixed>>
     */
    protected function scopedAll(string $table, string $where = '1=1', array $params = [], string $orderBy = ''): array
    {
        $t = $this->ident($table);
        $sql = "SELECT * FROM `$t` WHERE company_id = ? AND ($where)";
        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $this->orderBy($orderBy);
        }
        return $this->db->all($sql, [$this->ctx->id(), ...array_values($params)]);
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

    /** Updates a row of the context company; company_id in $row is ignored. Returns affected rows. */
    protected function scopedUpdate(string $table, int $id, array $row): int
    {
        $t = $this->ident($table);
        unset($row['company_id']);
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
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL identifier.');
        }
        return $name;
    }

    private function orderBy(string $orderBy): string
    {
        $parts = [];
        foreach (explode(',', $orderBy) as $part) {
            if (preg_match('/^\s*([a-z_][a-z0-9_]*)(?:\s+(ASC|DESC))?\s*$/i', $part, $m) !== 1) {
                throw new \InvalidArgumentException('Invalid ORDER BY.');
            }
            $parts[] = '`' . $m[1] . '`' . (isset($m[2]) ? ' ' . strtoupper($m[2]) : '');
        }
        return implode(', ', $parts);
    }
}
