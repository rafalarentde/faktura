<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * PDO whose prepare() first calls a hook with the SQL, to simulate concurrent writers or driver
 * errors. The hook may return a replacement SQL string (e.g. to strip FOR UPDATE for SQLite).
 */
final class HookPdo extends \PDO
{
    /** @var (\Closure(string, self): (string|void))|null */
    public ?\Closure $onPrepare = null;

    /** @var (\Closure(): void)|null called before a transaction is begun */
    public ?\Closure $onBegin = null;

    public function beginTransaction(): bool
    {
        if ($this->onBegin !== null) {
            ($this->onBegin)();
        }
        return parent::beginTransaction();
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->onPrepare !== null) {
            $replacement = ($this->onPrepare)($query, $this);
            if (is_string($replacement)) {
                $query = $replacement;
            }
        }
        return parent::prepare($query, $options);
    }
}
