<?php
declare(strict_types=1);

namespace Tests\Tenant;

use App\Core\Db;
use App\Tenant\AccessDenied;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

final class CompanyContextTest extends TestCase
{
    private Db $db;
    private int $user;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['u@example.pl', 'x']);
        $this->user = $this->db->lastId();
    }

    private function company(string $status, ?string $until = null, string $role = 'user'): CompanyContext
    {
        $this->db->exec('INSERT INTO companies (name, access_status, access_until) VALUES (?, ?, ?)', ['C', $status, $until]);
        $cid = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$cid, $this->user, $role]);
        return CompanyContext::forUser($this->db, $this->user, $cid);
    }

    public function test_accessors(): void
    {
        $ctx = $this->company('active', null, 'owner');
        $this->assertSame('owner', $ctx->role());
        $this->assertSame($this->user, $ctx->userId());
        $this->assertSame($ctx->id(), (int) $ctx->company()['id']);
        $this->assertSame('C', $ctx->company()['name']);
    }

    public function test_can_issue_matrix(): void
    {
        $now = new \DateTimeImmutable('2026-11-18 15:30:00');
        $this->assertTrue($this->company('active')->canIssue($now));
        $this->assertTrue($this->company('active', '2020-01-01')->canIssue($now), 'active ignores access_until');
        $this->assertTrue($this->company('trial', '2026-12-01')->canIssue($now));
        $this->assertTrue($this->company('trial', '2026-11-18')->canIssue($now), 'last day of trial is still valid');
        $this->assertFalse($this->company('trial', '2026-11-17')->canIssue($now), 'day after expiry');
        $this->assertFalse($this->company('trial', null)->canIssue($now), 'trial without date');
        $this->assertFalse($this->company('suspended')->canIssue($now));
        $this->assertFalse($this->company('suspended', '2030-01-01')->canIssue($now));
        $this->assertFalse($this->company('weird')->canIssue($now));
    }

    public function test_user_in_two_companies_gets_context_only_for_chosen_one(): void
    {
        $a = $this->company('active');
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', ['Other']);
        $other = $this->db->lastId();
        $this->assertSame($a->id(), CompanyContext::forUser($this->db, $this->user, $a->id())->id());
        $this->expectException(AccessDenied::class);
        CompanyContext::forUser($this->db, $this->user, $other);
    }

    public function test_access_denied_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new AccessDenied());
    }

    public function test_can_issue_uses_berlin_date(): void
    {
        $ctx = $this->company('trial', '2026-11-20');
        $utc = new \DateTimeZone('UTC');
        $this->assertFalse($ctx->canIssue(new \DateTimeImmutable('2026-11-20 23:30:00', $utc)), '00:30 on the 21st in Berlin');
        $this->assertTrue($ctx->canIssue(new \DateTimeImmutable('2026-11-20 22:59:00', $utc)), '23:59 on the 20th in Berlin');
    }
}
