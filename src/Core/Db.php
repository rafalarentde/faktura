<?php
declare(strict_types=1);

namespace App\Core;

/** Thin PDO wrapper; works with SQLite (tests) and MySQL (production). */
final class Db
{
    public function __construct(private \PDO $pdo, private string $driver)
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException("Unsupported driver: $driver");
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** Row-lock suffix to append to a SELECT inside a transaction. */
    public function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    /**
     * Runs $fn inside a transaction; rolls back and rethrows on any Throwable.
     * Nested calls join the outer transaction.
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $p = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Executes a statement; returns the number of affected rows. */
    public function exec(string $sql, array $p = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
