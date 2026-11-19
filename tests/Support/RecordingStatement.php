<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * PDOStatement that records every execute() as [queryString, params] in a static log
 * (install with PDO::ATTR_STATEMENT_CLASS). Used to check lock order by statement AND id.
 */
final class RecordingStatement extends \PDOStatement
{
    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    public static array $log = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$log[] = [$this->queryString, $params ?? []];
        return parent::execute($params);
    }
}
