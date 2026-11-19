<?php
declare(strict_types=1);

namespace Tests\Support;

/** PDO whose prepare() first calls a hook with the SQL, to simulate concurrent writers or driver errors. */
final class HookPdo extends \PDO
{
    /** @var (\Closure(string, self): void)|null */
    public ?\Closure $onPrepare = null;

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->onPrepare !== null) {
            ($this->onPrepare)($query, $this);
        }
        return parent::prepare($query, $options);
    }
}
