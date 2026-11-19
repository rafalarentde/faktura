<?php
declare(strict_types=1);

namespace Tests\Tenant;

use App\Core\Db;
use App\Repo\BaseRepo;
use App\Tenant\AccessDenied;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

/** Concrete repo exposing the protected scoped* helpers over a throwaway table. */
final class ItemRepo extends BaseRepo
{
    public function one(int $id): ?array { return $this->scopedOne('scoped_items', $id); }
    public function all(string $where = '1=1', array $p = [], string $orderBy = ''): array { return $this->scopedAll('scoped_items', $where, $p, $orderBy); }
    public function insert(array $row): int { return $this->scopedInsert('scoped_items', $row); }
    public function update(int $id, array $row): int { return $this->scopedUpdate('scoped_items', $id, $row); }
    public function delete(int $id): int { return $this->scopedDelete('scoped_items', $id); }
    public function count(string $where = '1=1', array $p = []): int { return $this->scopedCount('scoped_items', $where, $p); }
    public function page(int $limit, int $offset): array { return $this->scopedAll('scoped_items', '1=1', [], 'id', $limit, $offset); }
    public function bump(int $id, string $column = 'qty', int $by = 1): int { return $this->scopedIncrement('scoped_items', $id, $column, $by); }
    public function raise(int $id, string $column, int $value): int { return $this->scopedRaise('scoped_items', $id, $column, $value); }
    public function bumpMax(int $id, int $max): int { return $this->scopedIncrement('scoped_items', $id, 'qty', 1, $max); }
    public function onTable(string $table): ?array { return $this->scopedOne($table, 1); }
}

final class IsolationTest extends TestCase
{
    private Db $db;
    private int $companyA;
    private int $companyB;
    private int $userA;
    private int $userB;
    private int $itemA;
    private int $itemB;
    private ItemRepo $repoA;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->db->exec('CREATE TABLE scoped_items (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, name VARCHAR(50) NOT NULL, qty BIGINT NOT NULL DEFAULT 0)');
        $this->companyA = $this->company('A');
        $this->companyB = $this->company('B');
        $this->userA = $this->user('a@example.pl', $this->companyA);
        $this->userB = $this->user('b@example.pl', $this->companyB);
        $this->itemA = $this->item($this->companyA, 'a-item');
        $this->itemB = $this->item($this->companyB, 'b-item');
        $this->repoA = new ItemRepo($this->db, CompanyContext::forUser($this->db, $this->userA, $this->companyA));
    }

    private function company(string $name): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        return $this->db->lastId();
    }

    private function user(string $email, int $companyId, string $role = 'owner'): int
    {
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, 'x']);
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $id, $role]);
        return $id;
    }

    private function item(int $companyId, string $name): int
    {
        $this->db->exec('INSERT INTO scoped_items (company_id, name) VALUES (?, ?)', [$companyId, $name]);
        return $this->db->lastId();
    }

    private function rowB(): array
    {
        return $this->db->one('SELECT * FROM scoped_items WHERE id = ?', [$this->itemB]);
    }

    public function test_cannot_read_other_company_row_by_id(): void
    {
        $this->assertNull($this->repoA->one($this->itemB));
        $this->assertSame('a-item', $this->repoA->one($this->itemA)['name']);
    }

    public function test_cannot_update_or_delete_other_company_row(): void
    {
        $before = $this->rowB();
        $this->assertSame(0, $this->repoA->update($this->itemB, ['name' => 'hacked']));
        $this->assertSame(0, $this->repoA->delete($this->itemB));
        $this->assertSame($before, $this->rowB());
    }

    public function test_own_row_update_and_delete_work(): void
    {
        $this->assertSame(1, $this->repoA->update($this->itemA, ['name' => 'renamed']));
        $this->assertSame('renamed', $this->repoA->one($this->itemA)['name']);
        $this->assertSame(1, $this->repoA->delete($this->itemA));
        $this->assertNull($this->repoA->one($this->itemA));
    }

    public function test_insert_forces_own_company_id(): void
    {
        $id = $this->repoA->insert(['name' => 'new', 'company_id' => $this->companyB]);
        $row = $this->db->one('SELECT * FROM scoped_items WHERE id = ?', [$id]);
        $this->assertSame($this->companyA, (int) $row['company_id']);
        $this->assertNotNull($this->repoA->one($id));
    }

    public function test_update_never_changes_company_id(): void
    {
        $this->assertSame(1, $this->repoA->update($this->itemA, ['company_id' => $this->companyB, 'name' => 'x']));
        $row = $this->db->one('SELECT * FROM scoped_items WHERE id = ?', [$this->itemA]);
        $this->assertSame($this->companyA, (int) $row['company_id']);
        $this->assertSame('x', $row['name']);
        // Only company_id given: nothing to update, nothing changes.
        $this->assertSame(0, $this->repoA->update($this->itemA, ['company_id' => $this->companyB]));
        $this->assertSame($this->companyA, (int) $this->db->one('SELECT company_id FROM scoped_items WHERE id = ?', [$this->itemA])['company_id']);
    }

    public function test_scoped_all_lists_only_own_rows(): void
    {
        $this->item($this->companyA, 'a-two');
        $names = array_column($this->repoA->all('1=1', [], 'name DESC'), 'name');
        $this->assertSame(['a-two', 'a-item'], $names);
        $this->assertCount(1, $this->repoA->all('name = ?', ['a-item']));
        $this->assertSame([], $this->repoA->all('name = ?', ['b-item']));
    }

    public function test_or_in_where_cannot_escape_tenant_filter(): void
    {
        $this->assertSame(['a-item'], array_column($this->repoA->all('1=1 OR 1=1'), 'name'));
        $this->assertSame(['a-item'], array_column($this->repoA->all("name = 'x' OR company_id <> 0"), 'name'));
        $this->assertSame(['a-item'], array_column($this->repoA->all("name = ? OR company_id <> 0", ['nothing']), 'name'));
    }

    public function test_bad_table_and_column_names_are_rejected(): void
    {
        foreach (['scoped_items; DROP TABLE users', 'Scoped', '1abc', 'a b', '', 'x`y', 'scoped_items --'] as $bad) {
            try {
                $this->repoA->onTable($bad);
                $this->fail("Table name accepted: $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->repoA->insert(['name) VALUES (1); --' => 'x']);
    }

    public function test_bad_column_names_rejected_on_update_and_order(): void
    {
        foreach ([fn () => $this->repoA->update($this->itemA, ['name = 1, company_id' => 5]),
                  fn () => $this->repoA->all('1=1', [], 'name; DROP TABLE users')] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) AS c FROM scoped_items')['c']);
    }

    public function test_insert_of_empty_row_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repoA->insert([]);
    }

    public function test_user_without_membership_gets_access_denied(): void
    {
        $this->expectException(AccessDenied::class);
        CompanyContext::forUser($this->db, $this->userA, $this->companyB);
    }

    public function test_unknown_company_gets_access_denied(): void
    {
        $this->expectException(AccessDenied::class);
        CompanyContext::forUser($this->db, $this->userA, 9999);
    }

    public function test_company_row_exists_without_membership_is_denied_in_both_directions(): void
    {
        $ctxB = CompanyContext::forUser($this->db, $this->userB, $this->companyB);
        $repoB = new ItemRepo($this->db, $ctxB);
        $this->assertNull($repoB->one($this->itemA));
        $this->assertSame(0, $repoB->delete($this->itemA));
        $this->assertSame('a-item', $this->db->one('SELECT name FROM scoped_items WHERE id = ?', [$this->itemA])['name']);
    }

    public function test_where_cannot_break_out_of_parentheses(): void
    {
        foreach (['1=1) OR (1=1', '1=0) OR (company_id=' . $this->companyB, '1=0) OR company_id=' . $this->companyB . ' --',
                  '1=0) UNION SELECT * FROM scoped_items WHERE (1=1', '1=1;', '1=1 /* x */', "name = 'a", '(1=1', '1=1 # x', 'CAST(1 AS [(])=1) OR (1=1 OR CAST(1 AS [)])=1', '1=1 OR {fn ABS(1)}=1', 'name = ?1', "name = 'a\0' OR 1=1", "name = 'a\\' OR 1=1 --"] as $bad) {
            try {
                $rows = $this->repoA->all($bad);
                $this->assertNotContains('b-item', array_column($rows, 'name'), $bad);
                $this->fail("Accepted: $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->repoA->count($bad);
                $this->fail("Count accepted: $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['a-item'], array_column($this->repoA->all("name = 'it''s (x)' OR (name = ? AND 1=1)", ['a-item']), 'name'));
    }

    public function test_trailing_newline_in_identifiers_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repoA->onTable("scoped_items\n");
    }

    public function test_trailing_newline_in_column_is_rejected(): void
    {
        foreach ([fn () => $this->repoA->insert(["name\n" => 'x'])]  as $call) {
            try {
                $call();
                $this->fail('Accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_scoped_count_counts_only_own_rows(): void
    {
        $this->item($this->companyA, 'a-two');
        $this->assertSame(2, $this->repoA->count());
        $this->assertSame(1, $this->repoA->count('name = ?', ['a-two']));
        $this->assertSame(0, $this->repoA->count('name = ?', ['b-item']));
        $this->assertSame(2, $this->repoA->count('1=1 OR 1=1'));
    }

    public function test_limit_and_offset(): void
    {
        $this->item($this->companyA, 'a-two');
        $this->item($this->companyA, 'a-three');
        $this->assertSame(['a-item', 'a-two'], array_column($this->repoA->page(2, 0), 'name'));
        $this->assertSame(['a-three'], array_column($this->repoA->page(2, 2), 'name'));
        $this->expectException(\InvalidArgumentException::class);
        $this->repoA->page(-1, 0);
    }

    public function test_update_with_id_key_throws_and_changes_nothing(): void
    {
        try {
            $this->repoA->update($this->itemA, ['id' => $this->itemB, 'name' => 'x']);
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException) {
            $this->assertSame('a-item', $this->repoA->one($this->itemA)['name']);
        }
    }

    public function test_scoped_increment_adds_atomically_and_only_for_own_rows(): void
    {
        $this->assertSame(1, $this->repoA->bump($this->itemA));
        $this->assertSame(1, $this->repoA->bump($this->itemA, 'qty', 5));
        $this->assertSame(6, (int) $this->repoA->one($this->itemA)['qty']);
        $this->assertSame(0, $this->repoA->bump($this->itemB), 'another company row is not touched');
        $this->assertSame(0, (int) $this->rowB()['qty']);
    }

    public function test_scoped_increment_rejects_bad_columns(): void
    {
        foreach (['id', 'company_id', 'qty; DROP TABLE x', "qty\n", 'qty`', ''] as $col) {
            try {
                $this->repoA->bump($this->itemA, $col);
                $this->fail('Accepted column ' . $col);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->repoA->one($this->itemA)['qty']);
    }

    public function test_scoped_raise_is_monotonic_and_scoped(): void
    {
        $this->assertSame(1, $this->repoA->raise($this->itemA, 'qty', 5));
        $this->assertSame(0, $this->repoA->raise($this->itemA, 'qty', 3), 'never lowers');
        $this->assertSame(0, $this->repoA->raise($this->itemA, 'qty', 5), 'equal is no change');
        $this->assertSame(5, (int) $this->repoA->one($this->itemA)['qty']);
        $this->assertSame(0, $this->repoA->raise($this->itemB, 'qty', 9), 'other company row untouched');
        foreach (['id', 'company_id', 'qty; x'] as $col) {
            try {
                $this->repoA->raise($this->itemA, $col, 99);
                $this->fail($col);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_scoped_increment_respects_the_max(): void
    {
        $this->assertSame(1, $this->repoA->bumpMax($this->itemA, 2));
        $this->assertSame(1, $this->repoA->bumpMax($this->itemA, 2));
        $this->assertSame(0, $this->repoA->bumpMax($this->itemA, 2), 'would pass the limit');
        $this->assertSame(2, (int) $this->repoA->one($this->itemA)['qty']);
    }
}
