<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Domain\DocumentNotFound;
use App\Repo\EmailLogRepo;
use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;

/** email_log is reached only through the company-scoped EmailLogRepo. */
final class EmailLogRepoTest extends TestCase
{
    private PdfFixture $fx;
    private int $docA;
    private int $docB;

    protected function setUp(): void
    {
        $this->fx = new PdfFixture();
        $this->docA = $this->fx->issued($this->fx->companyA, $this->fx->customer($this->fx->companyA));
        $this->docB = $this->fx->issued($this->fx->companyB, $this->fx->customer($this->fx->companyB));
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function repo(int $company): EmailLogRepo
    {
        return new EmailLogRepo($this->fx->db, $this->fx->ctx($company));
    }

    public function test_records_and_lists_newest_first(): void
    {
        $r = $this->repo($this->fx->companyA);
        $r->record($this->docA, 'a@example.com', 'Subject 1', 'failed', 'auth', $this->fx->userId, 1_795_514_400);
        $r->record($this->docA, 'b@example.com', 'Subject 2', 'sent', null, $this->fx->userId, 1_795_514_500);
        $rows = $r->forDocument($this->docA);
        $this->assertCount(2, $rows);
        $this->assertSame('b@example.com', $rows[0]['to_email']);
        $this->assertSame('sent', $rows[0]['status']);
        $this->assertNull($rows[0]['error']);
        $this->assertSame('auth', $rows[1]['error']);
        $this->assertSame('2026-11-24 10:00:00', $rows[1]['created_at']);
        $this->assertTrue($r->hasSent($this->docA));
    }

    public function test_has_sent_only_counts_successful_sends(): void
    {
        $r = $this->repo($this->fx->companyA);
        $r->record($this->docA, 'a@example.com', 'S', 'failed', 'connect', null, 1_795_514_400);
        $this->assertFalse($r->hasSent($this->docA));
    }

    public function test_company_b_rows_are_invisible_to_a(): void
    {
        $this->repo($this->fx->companyB)->record($this->docB, 'x@example.com', 'S', 'sent', null, $this->fx->userId, 1_795_514_400);
        $this->assertSame([], $this->repo($this->fx->companyA)->forDocument($this->docB));
        $this->assertFalse($this->repo($this->fx->companyA)->hasSent($this->docB));
        $this->assertCount(1, $this->repo($this->fx->companyB)->forDocument($this->docB));
    }

    public function test_cannot_insert_for_another_companys_document(): void
    {
        try {
            $this->repo($this->fx->companyA)->record($this->docB, 'x@example.com', 'S', 'sent', null, null, 1_795_514_400);
            $this->fail('expected DocumentNotFound');
        } catch (DocumentNotFound) {
            $this->assertSame(0, (int) $this->fx->db->one('SELECT COUNT(*) AS n FROM email_log')['n']);
        }
    }

    public function test_rejects_unknown_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo($this->fx->companyA)->record($this->docA, 'a@example.com', 'S', 'queued', null, null, 1_795_514_400);
    }

    public function test_recipient_column_holds_a_full_length_address(): void
    {
        $to = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 58) . '.de';
        $this->assertSame(254, strlen($to));
        $this->repo($this->fx->companyA)->record($this->docA, $to, 'S', 'sent', null, null, 1_795_514_400);
        $this->assertSame($to, $this->repo($this->fx->companyA)->forDocument($this->docA)[0]['to_email']);
    }
}
