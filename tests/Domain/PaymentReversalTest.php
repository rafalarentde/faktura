<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\DocumentNotFound;
use App\Domain\DocumentService;
use App\Domain\ValidationException;
use App\Repo\ReportRepo;
use PHPUnit\Framework\TestCase;
use Tests\Pdf\PdfFixture;

/** I5 (payment reversal, "Storno") and M6 (paid_on not in the future). */
final class PaymentReversalTest extends TestCase
{
    private PdfFixture $f;
    private int $a;
    private int $customer;
    private DocumentService $svc;
    private int $invoice; // gross 1.012,50? see setUp

    protected function setUp(): void
    {
        $this->f = new PdfFixture();
        $this->a = $this->f->companyA;
        $this->customer = $this->f->customer($this->a);
        $this->svc = $this->f->service($this->a);
        $this->invoice = $this->f->issued($this->a, $this->customer); // issued 2026-11-18, due 2026-12-02
    }

    private function day(string $d): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d . ' 12:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    private function gross(): int
    {
        return $this->f->repo($this->a)->row($this->invoice)['gross_minor'];
    }

    private function summary(): array
    {
        return $this->svc->paymentSummary($this->invoice, $this->day('2026-11-24'));
    }

    /** @return list<array<string, mixed>> */
    private function payments(): array
    {
        return $this->f->repo($this->a)->payments($this->invoice);
    }

    /** @return array<string, string> */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('Expected ValidationException');
    }

    public function test_reversal_inserts_a_negative_row_and_reopens_the_invoice(): void
    {
        $gross = $this->gross();
        $this->svc->addPayment($this->invoice, $gross, $this->day('2026-11-20'));
        $this->assertSame('paid', $this->summary()['status']);
        $original = $this->payments()[0]['id'];

        $rid = $this->svc->reversePayment($this->invoice, $original, $this->day('2026-11-23'), 'Rücklastschrift');

        $rows = $this->payments();
        $this->assertCount(2, $rows);
        $storno = array_values(array_filter($rows, fn ($r) => $r['id'] === $rid))[0];
        $this->assertSame(-$gross, $storno['amount_minor']);
        $this->assertSame($original, $storno['reverses_payment_id']);
        $this->assertSame('2026-11-23', $storno['paid_on']);
        $this->assertSame('Rücklastschrift', $storno['note']);
        $s = $this->summary();
        $this->assertSame(0, $s['paid_minor']);
        $this->assertSame($gross, $s['balance_minor']);
        $this->assertSame('overdue', $this->svc->paymentSummary($this->invoice, $this->day('2026-12-05'))['status']);
        $this->assertSame('open', $s['status']);
    }

    public function test_partial_reversal_keeps_the_other_payment(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $this->svc->addPayment($this->invoice, 5000, $this->day('2026-11-20'));
        $second = $this->payments()[1]['id'];
        $this->svc->reversePayment($this->invoice, $second, $this->day('2026-11-21'));
        $s = $this->summary();
        $this->assertSame(10000, $s['paid_minor']);
        $this->assertSame('partial', $s['status']);
    }

    public function test_each_payment_can_be_reversed_only_once(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $pid = $this->payments()[0]['id'];
        $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-20'));
        $this->assertSame(['payment' => 'document.err_reverse_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-21'))));
        $this->assertCount(2, $this->payments());
    }

    public function test_a_reversal_row_cannot_itself_be_reversed(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $rid = $this->svc->reversePayment($this->invoice, $this->payments()[0]['id'], $this->day('2026-11-20'));
        $this->assertSame(['payment' => 'document.err_reverse_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, $rid, $this->day('2026-11-21'))));
    }

    public function test_the_database_refuses_a_second_reversal_of_the_same_payment(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $pid = $this->payments()[0]['id'];
        $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-20'));
        $this->expectException(\PDOException::class);
        $this->f->db->exec('INSERT INTO payments (company_id, document_id, amount_minor, paid_on, reverses_payment_id) VALUES (?, ?, -1, ?, ?)',
            [$this->a, $this->invoice, '2026-11-21', $pid]);
    }

    public function test_payment_of_another_document_or_unknown_id_is_refused(): void
    {
        $other = $this->f->issued($this->a, $this->customer);
        $this->svc->addPayment($other, 10000, $this->day('2026-11-19'));
        $foreign = $this->f->repo($this->a)->payments($other)[0]['id'];
        $this->assertSame(['payment' => 'document.err_reverse_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, $foreign, $this->day('2026-11-20'))));
        $this->assertSame(['payment' => 'document.err_reverse_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, 99999, $this->day('2026-11-20'))));
    }

    public function test_only_on_issued_invoices(): void
    {
        $draft = $this->f->draft($this->a, $this->customer);
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($draft, 1, $this->day('2026-11-20'))));
        $offer = $this->svc->createDraft('offer', ['customer_id' => $this->customer, 'lines' => [PdfFixture::line()]]);
        $this->svc->issue($offer, new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('UTC')), true);
        $this->assertSame(['document' => 'document.err_payment_not_allowed'], $this->errors(fn () => $this->svc->reversePayment($offer, 1, $this->day('2026-11-20'))));
    }

    public function test_other_companys_invoice_is_not_found(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $pid = $this->payments()[0]['id'];
        $this->expectException(DocumentNotFound::class);
        $this->f->service($this->f->companyB)->reversePayment($this->invoice, $pid, $this->day('2026-11-20'));
    }

    public function test_reversal_date_rules(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-20'));
        $pid = $this->payments()[0]['id'];
        $this->assertSame(['paid_on' => 'document.err_paid_on'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-19'))));
        $this->assertSame(['paid_on' => 'document.err_paid_on_future'], $this->errors(fn () => $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-25'))));
        $this->assertCount(1, $this->payments());
    }

    public function test_reversal_is_audited_with_ids_only(): void
    {
        $this->svc->addPayment($this->invoice, 10000, $this->day('2026-11-19'));
        $pid = $this->payments()[0]['id'];
        $rid = $this->svc->reversePayment($this->invoice, $pid, $this->day('2026-11-20'), 'x');
        $rows = $this->f->db->all("SELECT * FROM audit_log WHERE action = 'document.payment_reverse'");
        $this->assertCount(1, $rows);
        $this->assertSame(['document_id' => $this->invoice, 'payment_id' => $pid, 'reversal_id' => $rid], json_decode($rows[0]['detail'], true));
        $this->assertSame($this->a, (int) $rows[0]['company_id']);
    }

    public function test_aggregates_use_the_net_sum(): void
    {
        $gross = $this->gross();
        $this->svc->addPayment($this->invoice, $gross, $this->day('2026-11-20'));
        $this->svc->reversePayment($this->invoice, $this->payments()[0]['id'], $this->day('2026-11-21'));
        $today = $this->day('2026-11-24');
        $rows = $this->f->repo($this->a)->paymentRows(['ids' => [$this->invoice]], $today);
        $this->assertSame(0, $rows[0]['paid_minor']);
        $this->assertSame($gross, $rows[0]['balance_minor']);
        $this->assertSame('open', $rows[0]['pay_status']);
        // an open-amount card counts it again, the month's paid card nets to zero
        $t = (new ReportRepo($this->f->db, $this->f->ctx($this->a)))->totals($today);
        $this->assertSame($gross, $t['open']['EUR']['minor']);
        $this->assertSame(0, $t['paid_month']['EUR']['minor'] ?? 0);
        $this->assertSame(0, $t['paid_month']['EUR']['count'] ?? 0); // no real payment left in the month
    }

    public function test_reopened_invoice_can_be_dunned_again(): void
    {
        $at = new \DateTimeImmutable('2026-12-10 10:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->svc->addPayment($this->invoice, $this->gross(), $this->day('2026-11-20'));
        $this->assertSame(['invoice' => 'dunning.err_paid'], $this->errors(fn () => $this->svc->dunningContext($this->invoice, $at)));
        $this->svc->reversePayment($this->invoice, $this->payments()[0]['id'], $this->day('2026-11-21'));
        $ctx = $this->svc->dunningContext($this->invoice, $at);
        $this->assertSame($this->gross(), $ctx['outstanding_minor']);
    }

    // ---- M6 ----

    public function test_paid_on_in_the_future_is_refused(): void
    {
        $this->assertSame(['paid_on' => 'document.err_paid_on_future'], $this->errors(fn () => $this->svc->addPayment($this->invoice, 100, $this->day('2026-11-25'))));
        $this->svc->addPayment($this->invoice, 100, $this->day('2026-11-24')); // today is fine
        $this->assertCount(1, $this->payments());
    }
}
