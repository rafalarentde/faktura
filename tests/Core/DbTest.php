<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Db;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    private function makeDb(string $driver = 'sqlite'): Db
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $db = new Db($pdo, $driver);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(50))');
        return $db;
    }

    private static function mysqlError(int $code): \PDOException
    {
        $e = new \PDOException('SQLSTATE[40001]: simulated');
        $e->errorInfo = ['40001', $code, 'simulated'];
        return $e;
    }

    public function test_retry_on_lock_error_runs_the_callable_once_more_for_deadlock_and_lock_wait(): void
    {
        foreach ([1213, 1205] as $code) {
            $db = $this->makeDb('mysql'); // the driver name only; the connection is SQLite
            $calls = 0;
            $r = $db->retryOnLockError(function () use (&$calls, $code): string {
                if (++$calls === 1) {
                    throw self::mysqlError($code);
                }
                return 'ok';
            });
            $this->assertSame('ok', $r);
            $this->assertSame(2, $calls, "error $code");
        }
    }

    public function test_retry_happens_only_once_and_only_for_lock_errors(): void
    {
        $db = $this->makeDb('mysql');
        $calls = 0;
        try {
            $db->retryOnLockError(function () use (&$calls): void {
                $calls++;
                throw self::mysqlError(1213);
            });
            $this->fail('the second failure propagates');
        } catch (\PDOException $e) {
            $this->assertSame(1213, $e->errorInfo[1]);
        }
        $this->assertSame(2, $calls);

        foreach ([self::mysqlError(1062), new \RuntimeException('x'), new \PDOException('no error info')] as $other) {
            $calls = 0;
            try {
                $db->retryOnLockError(function () use (&$calls, $other): void {
                    $calls++;
                    throw $other;
                });
            } catch (\Throwable) {
            }
            $this->assertSame(1, $calls, get_class($other));
        }
    }

    public function test_retry_is_not_attempted_inside_an_open_transaction_or_on_sqlite(): void
    {
        $db = $this->makeDb('mysql');
        $calls = 0;
        try {
            $db->transaction(function () use ($db, &$calls): void {
                $db->retryOnLockError(function () use (&$calls): void {
                    $calls++;
                    throw self::mysqlError(1213);
                });
            });
        } catch (\PDOException) {
        }
        $this->assertSame(1, $calls, 'the outer transaction is already rolled back by the server: only its owner may retry');

        $sqlite = $this->makeDb('sqlite');
        $calls = 0;
        try {
            $sqlite->retryOnLockError(function () use (&$calls): void {
                $calls++;
                throw self::mysqlError(1213);
            });
        } catch (\PDOException) {
        }
        $this->assertSame(1, $calls);
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        $db = $this->makeDb();
        try {
            $db->transaction(function (Db $d) {
                $d->exec('INSERT INTO t (v) VALUES (?)', ['a']);
                throw new \RuntimeException('boom');
            });
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame([], $db->all('SELECT * FROM t'));
    }

    public function test_transaction_commits_and_returns_value(): void
    {
        $db = $this->makeDb();
        $r = $db->transaction(function (Db $d) {
            $d->exec('INSERT INTO t (v) VALUES (?)', ['a']);
            return $d->lastId();
        });
        $this->assertSame(1, $r);
        $this->assertSame('a', $db->one('SELECT v FROM t WHERE id = ?', [1])['v']);
        $this->assertNull($db->one('SELECT v FROM t WHERE id = ?', [99]));
    }

    public function test_for_update_suffix_per_driver(): void
    {
        $this->assertSame('', $this->makeDb('sqlite')->forUpdate());
        $this->assertSame(' FOR UPDATE', $this->makeDb('mysql')->forUpdate());
        $this->assertSame('mysql', $this->makeDb('mysql')->driver());
    }
}
