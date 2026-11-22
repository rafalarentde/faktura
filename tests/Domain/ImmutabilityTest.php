<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\DocumentService;
use App\Domain\DunningLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;

/**
 * T19: an issued document never changes. Every column of the document row, of its lines and of its
 * payments is compared before and after attempted mutations through EVERY writer (service methods and
 * repository writers), for each issued kind. The only column that may move is offer_status (+ updated_at)
 * of an issued offer, through its dedicated methods.
 */
final class ImmutabilityTest extends TestCase
{
    private PdfFixture $f;
    private int $a;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->f = new PdfFixture();
        $this->a = $this->f->companyA;
        $customer = $this->f->customer($this->a);
        $svc = $this->f->service($this->a);
        $this->ids['invoice'] = $this->f->issued($this->a, $customer);
        $svc->addPayment($this->ids['invoice'], 5000, new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('Europe/Berlin')), 'Anzahlung');
        $this->ids['credit_note'] = $this->f->creditNote($this->a, $this->f->issued($this->a, $customer), [PdfFixture::line(['unit_minor' => 5000])]);
        $offer = $svc->createDraft('offer', ['customer_id' => $customer, 'lines' => PdfFixture::makietaLines(), 'issue_date' => '2026-11-18']);
        $svc->issue($offer, new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->ids['offer'] = $offer;
        $this->ids['dunning'] = $svc->createDunning($this->ids['invoice'], DunningLevel::Reminder, false, false, false,
            new \DateTimeImmutable('2026-12-10 10:00:00', new \DateTimeZone('Europe/Berlin')));
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['invoice' => ['invoice'], 'credit note' => ['credit_note'], 'offer' => ['offer'], 'dunning letter' => ['dunning']];
    }

    /** @return array{doc: array<string, mixed>, lines: list<array<string, mixed>>, payments: list<array<string, mixed>>} every column */
    private function snapshot(int $id): array
    {
        $db = $this->f->db;
        return [
            'doc' => $db->one('SELECT * FROM documents WHERE id = ?', [$id]) ?? [],
            'lines' => $db->all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY id', [$id]),
            'payments' => $db->all('SELECT * FROM payments WHERE document_id = ? ORDER BY id', [$id]),
        ];
    }

    /** Runs every writer with plausible and hostile input against $id; each must throw or do nothing. */
    private function attackAll(int $id): void
    {
        $svc = $this->f->service($this->a);
        $repo = $this->f->repo($this->a);
        $line = PdfFixture::line(['name' => 'HACKED', 'unit_minor' => 1]);
        $attempts = [
            'updateDraft' => fn () => $svc->updateDraft($id, ['lines' => [$line], 'notes' => 'HACKED', 'issue_date' => '2020-01-01', 'service_date' => '2020-01-01',
                'due_date' => '2030-01-01', 'lang' => 'en', 'currency' => 'USD', 'eur_rate' => '1.0', 'intro_text' => 'HACKED', 'customer_id' => 999]),
            'deleteDraft' => fn () => $svc->deleteDraft($id),
            'issue again' => fn () => $svc->issue($id, new \DateTimeImmutable('2026-12-20 10:00:00', new \DateTimeZone('UTC')), true),
            'createCorrection' => fn () => $svc->createCorrection($id),
            'convertOffer' => fn () => $svc->convertOffer($id),
            'setOfferStatus invalid' => fn () => $svc->setOfferStatus($id, 'HACKED'),
            'repo updateDraftRow' => fn () => $repo->updateDraftRow($id, ['number' => 'HACKED', 'gross_minor' => 1, 'net_minor' => 1, 'vat_minor' => 1, 'seller_json' => '{}',
                'buyer_json' => '{}', 'buyer_name' => 'HACKED', 'status' => 'draft', 'issue_date' => '2020-01-01', 'type' => 'offer', 'company_id' => 999,
                'dunning_claim_minor' => 1, 'dunning_level' => 3, 'reverse_charge' => 1, 'vat_note_key' => 'x', 'due_date' => '2030-01-01', 'valid_until' => '2030-01-01']),
            'repo replaceLines' => fn () => $repo->replaceLines($id, [$line]),
            'repo deleteDraft' => fn () => $repo->deleteDraft($id),
            'repo insertDraft overwrite' => fn () => $repo->insertDraft(['id' => $id, 'type' => 'invoice']),
            'service dunning on a dunning' => fn () => $svc->createDunning($id, DunningLevel::First, true, true, true, new \DateTimeImmutable('2027-01-01 10:00:00', new \DateTimeZone('UTC'))),
        ];
        if ($this->kind($id) !== 'offer') {
            $attempts['repo updateOfferStatus'] = fn () => $repo->updateOfferStatus($id, 'accepted', '2026-12-20 10:00:00');
            $attempts['setOfferStatus'] = fn () => $svc->setOfferStatus($id, 'accepted');
            $attempts['markOfferSent'] = fn () => $svc->markOfferSent($id);
        }
        foreach ($attempts as $name => $call) {
            try {
                $call();
            } catch (\Throwable) {
                // refusing is the expected outcome; the snapshot comparison is the assertion
            }
        }
    }

    private function kind(int $id): string
    {
        return (string) $this->f->db->one('SELECT type FROM documents WHERE id = ?', [$id])['type'];
    }

    #[DataProvider('kinds')]
    public function test_no_writer_changes_an_issued_document(string $kind): void
    {
        $id = $this->ids[$kind];
        $before = $this->snapshot($id);
        $this->assertSame('issued', $before['doc']['status']);
        $this->attackAll($id);
        $after = $this->snapshot($id);
        if ($kind === 'invoice') {
            // the attempted correction created a NEW draft credit note, not a change of the invoice
            $this->assertSame($before, $after);
            return;
        }
        $this->assertSame($before['doc'], $after['doc'], "$kind: some column of the document row changed");
        $this->assertSame($before['lines'], $after['lines'], "$kind: lines changed");
        $this->assertSame($before['payments'], $after['payments'], "$kind: payments changed");
    }

    public function test_payments_of_an_issued_invoice_are_never_updated_or_deleted(): void
    {
        $id = $this->ids['invoice'];
        $svc = $this->f->service($this->a);
        $pid = (int) $this->f->db->one('SELECT id FROM payments WHERE document_id = ?', [$id])['id'];
        $before = $this->snapshot($id);
        $svc->addPayment($id, 100, new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $svc->reversePayment($id, $pid, new \DateTimeImmutable('2026-11-22 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $after = $this->snapshot($id);
        $this->assertSame($before['doc'], $after['doc'], 'the document row is untouched by payments');
        $this->assertSame($before['payments'][0], $after['payments'][0], 'the original payment row is unchanged');
        $this->assertCount(3, $after['payments']);
    }

    public function test_only_offer_status_and_updated_at_move_on_an_issued_offer(): void
    {
        $id = $this->ids['offer'];
        $svc = $this->f->service($this->a);
        $before = $this->snapshot($id);
        $svc->markOfferSent($id);
        $svc->setOfferStatus($id, 'accepted');
        $after = $this->snapshot($id);
        $changed = array_keys(array_diff_assoc($after['doc'], $before['doc']));
        $this->assertContains('offer_status', $changed);
        $this->assertSame([], array_diff($changed, ['offer_status', 'updated_at']), 'nothing but offer_status (and its timestamp) moved');
        $this->assertSame('accepted', $after['doc']['offer_status']);
        $this->assertSame($before['lines'], $after['lines']);
    }

    public function test_the_draft_defaults_never_prefill_valid_until_into_an_invoice(): void
    {
        $ref = new \ReflectionMethod(\App\Http\InvoiceController::class, 'defaultDates');
        $ref->setAccessible(true);
        $invoice = (new \ReflectionClass(\App\Http\InvoiceController::class))->newInstanceWithoutConstructor();
        $dates = $ref->invoke($invoice, ['payment_days' => 14], new \DateTimeImmutable('2026-11-18'));
        $this->assertArrayNotHasKey('valid_until', $dates);
        $offer = (new \ReflectionClass(\App\Http\OfferController::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionMethod(\App\Http\OfferController::class, 'defaultDates');
        $ref->setAccessible(true);
        $this->assertArrayHasKey('valid_until', $ref->invoke($offer, [], new \DateTimeImmutable('2026-11-18')));
    }
}
