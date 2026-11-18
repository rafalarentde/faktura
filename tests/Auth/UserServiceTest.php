<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\UserService;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function test_create_lowercases_and_hashes(): void
    {
        $db = \TestDb::fresh();
        $id = (new UserService($db))->create('  Anna@Example.PL ', 'twelve chars!', true);
        $row = $db->one('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame('anna@example.pl', $row['email']);
        $this->assertStringStartsWith('$argon2id$', $row['password_hash']);
        $this->assertTrue(password_verify('twelve chars!', $row['password_hash']));
        $this->assertSame(1, (int) $row['is_admin']);
        $this->assertSame(0, (int) $row['totp_enabled']);
    }

    public function test_rejects_duplicate_email_case_insensitively(): void
    {
        $svc = new UserService(\TestDb::fresh());
        $svc->create('anna@example.pl', 'twelve chars!', false);
        $this->expectException(\InvalidArgumentException::class);
        $svc->create('ANNA@example.pl', 'twelve chars!', false);
    }

    public function test_rejects_short_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UserService(\TestDb::fresh()))->create('a@example.pl', 'elevenchars', false);
    }

    public function test_rejects_invalid_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UserService(\TestDb::fresh()))->create('not-an-email', 'twelve chars!', false);
    }
}
