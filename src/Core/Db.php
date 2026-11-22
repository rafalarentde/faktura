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

    /**
     * Runs $fn (a whole unit of work that opens and commits its own transaction) and, on MySQL only, runs it
     * ONE more time when the server aborted it with a deadlock (1213) or a lock wait timeout (1205). Both mean
     * nothing of the first run was kept, so a second run is safe. Not retried inside an already open
     * transaction (the server rolled that one back as a whole: only its owner can redo it), and any other
     * error, or a second failure, propagates unchanged.
     */
    public function retryOnLockError(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($this->driver !== 'mysql' || !in_array($code, [1213, 1205], true) || $this->pdo->inTransaction()) {
                throw $e;
            }
        }
        return $fn();
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
