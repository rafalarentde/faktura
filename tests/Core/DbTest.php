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
