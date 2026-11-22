<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Auth\UserService;
use App\Core\Db;
use App\Repo\CompanyRepo;
use PHPUnit\Framework\TestCase;

final class CompanyRepoTest extends TestCase
{
    private Db $db;
    private CompanyRepo $repo;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->repo = new CompanyRepo($this->db, new UserService($this->db));
    }

    private function user(string $email = 'a@example.com'): int
    {
        return (new UserService($this->db))->create($email, 'correct horse battery', false);
    }

    public function test_create_defaults_to_suspended_free(): void
    {
        $id = $this->repo->create(['name' => '  Acme GmbH ']);
        $c = $this->repo->find($id);
        $this->assertSame('Acme GmbH', $c['name']);
        $this->assertSame('free', $c['plan']);
        $this->assertSame('suspended', $c['access_status']);
        $this->assertNull($c['access_until']);
    }

    public function test_create_with_explicit_status_and_until(): void
    {
        $id = $this->repo->create(['name' => 'T', 'plan' => 'standard', 'access_status' => 'trial', 'access_until' => '2027-01-31']);
        $c = $this->repo->find($id);
        $this->assertSame('standard', $c['plan']);
        $this->assertSame('trial', $c['access_status']);
        $this->assertSame('2027-01-31', substr((string) $c['access_until'], 0, 10));
    }

    public function test_create_requires_name_and_valid_values(): void
    {
        foreach ([
            ['name' => '   '],
            [],
            ['name' => 'X', 'access_status' => 'bogus'],
            ['name' => 'X', 'access_status' => 'trial'],
            ['name' => 'X', 'access_status' => 'active', 'access_until' => '31.01.2027'],
            ['name' => 'X', 'plan' => 'Bad Plan!'],
        ] as $data) {
            try {
                $this->repo->create($data);
                $this->fail('Expected exception for ' . json_encode($data));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->repo->all());
    }

    public function test_all_counts_users_and_find_unknown_is_null(): void
    {
        $c = $this->repo->create(['name' => 'A']);
        $this->repo->create(['name' => 'B']);
        $this->repo->addUser($c, 'a@example.com', 'owner', 'correct horse battery');
        $rows = array_column($this->repo->all(), null, 'name');
        $this->assertSame(1, (int) $rows['A']['user_count']);
        $this->assertSame(0, (int) $rows['B']['user_count']);
        $this->assertNull($this->repo->find(9999));
    }

    public function test_set_access_validates_and_does_not_change_on_error(): void
    {
        $id = $this->repo->create(['name' => 'A']);
        $this->repo->setAccess($id, 'active', '2027-05-01');
        $this->assertSame('active', $this->repo->find($id)['access_status']);
        foreach ([['bogus', null], ['trial', null], ['active', '2027-13-45'], ['active', 'tomorrow'], ['active', '2027-02-30']] as [$s, $u]) {
            try {
                $this->repo->setAccess($id, $s, $u);
                $this->fail("Expected exception for $s/$u");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $c = $this->repo->find($id);
        $this->assertSame('active', $c['access_status']);
        $this->assertSame('2027-05-01', substr((string) $c['access_until'], 0, 10));
        $this->repo->setAccess($id, 'suspended', null);
        $this->assertNull($this->repo->find($id)['access_until']);
    }

    public function test_set_access_unknown_company_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setAccess(999, 'active', null);
    }

    public function test_add_existing_user_by_lowercased_email(): void
    {
        $uid = $this->user('jan@example.com');
        $c = $this->repo->create(['name' => 'A']);
        $this->assertSame($uid, $this->repo->addUser($c, ' JAN@Example.com ', 'user'));
        $m = $this->repo->members($c);
        $this->assertCount(1, $m);
        $this->assertSame('user', $m[0]['role']);
    }

    public function test_add_user_creates_new_non_admin_with_password(): void
    {
        $c = $this->repo->create(['name' => 'A']);
        $uid = $this->repo->addUser($c, 'New@Example.com', 'owner', 'correct horse battery');
        $row = $this->db->one('SELECT email, is_admin FROM users WHERE id = ?', [$uid]);
        $this->assertSame('new@example.com', $row['email']);
        $this->assertSame(0, (int) $row['is_admin']);
    }

    public function test_add_user_errors(): void
    {
        $c = $this->repo->create(['name' => 'A']);
        $uid = $this->user('jan@example.com');
        $this->repo->addUser($c, 'jan@example.com', 'user');
        $cases = [
            [$c, 'unknown@example.com', 'user', null],          // unknown, no password
            [$c, 'jan@example.com', 'user', null],              // duplicate membership
            [$c, 'jan@example.com', 'user', 'correct horse battery'], // duplicate even with password
            [$c, 'x@example.com', 'admin', 'correct horse battery'], // invalid role
            [$c, 'not-an-email', 'user', 'correct horse battery'],
            [$c, 'short@example.com', 'user', 'short'],         // weak password
            [9999, 'jan@example.com', 'user', null],            // unknown company
        ];
        foreach ($cases as [$cid, $e, $r, $p]) {
            try {
                $this->repo->addUser($cid, $e, $r, $p);
                $this->fail("Expected exception for $e/$r");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($this->db->one('SELECT id FROM users WHERE email = ?', ['short@example.com']));
        $this->assertNull($this->db->one('SELECT id FROM users WHERE email = ?', ['x@example.com']));
        $this->assertCount(1, $this->repo->members($c));
        $this->assertSame($uid, (int) $this->repo->members($c)[0]['user_id']);
    }

    public function test_remove_user(): void
    {
        $c = $this->repo->create(['name' => 'A']);
        $d = $this->repo->create(['name' => 'D']);
        $u = $this->repo->addUser($c, 'a@example.com', 'user', 'correct horse battery');
        $this->repo->addUser($d, 'a@example.com', 'user');
        $this->repo->removeUser($c, $u);
        $this->assertSame([], $this->repo->members($c));
        $this->assertCount(1, $this->repo->members($d));
        $this->assertNotNull($this->db->one('SELECT id FROM users WHERE id = ?', [$u]));
    }

    public function test_reset_two_factor_clears_secret_flag_step_and_codes_of_that_user_only(): void
    {
        $a = $this->user('a@example.com');
        $b = $this->user('b@example.com');
        $this->db->exec('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = 77 WHERE id IN (?, ?)', ['enc', $a, $b]);
        $codes = new \App\Auth\BackupCodes($this->db);
        $codes->generate($a);
        $codes->generate($b);
        $this->repo->resetTwoFactor($a);
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$a]);
        $this->assertNull($row['totp_secret_enc']);
        $this->assertSame(0, (int) $row['totp_enabled']);
        $this->assertNull($row['totp_last_step']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM backup_codes WHERE user_id = ?', [$a])['c']);
        $this->assertSame(1, (int) $this->db->one('SELECT totp_enabled t FROM users WHERE id = ?', [$b])['t']);
        $this->assertSame(8, (int) $this->db->one('SELECT COUNT(*) c FROM backup_codes WHERE user_id = ?', [$b])['c']);
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        $c = $this->repo->create(['name' => 'Acme']);
        $owner = $this->repo->addUser($c, 'owner@example.com', 'owner', 'correct horse battery');
        $user = $this->repo->addUser($c, 'user@example.com', 'user', 'correct horse battery');
        try {
            $this->repo->removeUser($c, $owner);
            $this->fail('expected the last owner to be protected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('admin.err_last_owner', $e->getMessage());
        }
        $this->assertTrue($this->repo->isMember($c, $owner));
        $this->repo->removeUser($c, $user); // an ordinary member can go
        $this->assertFalse($this->repo->isMember($c, $user));
        $second = $this->repo->addUser($c, 'second@example.com', 'owner', 'correct horse battery');
        $this->repo->removeUser($c, $owner); // two owners: one may go
        $this->assertFalse($this->repo->isMember($c, $owner));
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->removeUser($c, $second); // now the last one
    }

    public function test_removing_a_non_member_is_a_noop(): void
    {
        $c = $this->repo->create(['name' => 'Acme']);
        $this->repo->addUser($c, 'owner@example.com', 'owner', 'correct horse battery');
        $this->repo->removeUser($c, 99999);
        $this->assertCount(1, $this->repo->members($c));
    }

    public function test_is_member(): void
    {
        $c = $this->repo->create(['name' => 'Acme']);
        $u = $this->user();
        $this->assertFalse($this->repo->isMember($c, $u));
        $this->repo->addUser($c, 'a@example.com', 'user');
        $this->assertTrue($this->repo->isMember($c, $u));
        $this->assertFalse($this->repo->isMember($c + 1, $u));
    }
}
