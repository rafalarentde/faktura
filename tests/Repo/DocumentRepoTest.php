<?php
declare(strict_types=1);

namespace Tests\Repo;

use App\Core\Db;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;
use PHPUnit\Framework\TestCase;

final class DocumentRepoTest extends TestCase
{
    private Db $db;
    private int $userId;
    private int $companyA;
    private int $companyB;

    protected function setUp(): void
    {
        $this->db = \TestDb::fresh();
        $this->db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ['o@example.com', 'x']);
        $this->userId = $this->db->lastId();
        $this->companyA = $this->company('A GmbH');
        $this->companyB = $this->company('B GmbH');
    }

    private function company(string $name): int
    {
        $this->db->exec('INSERT INTO companies (name) VALUES (?)', [$name]);
        $id = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$id, $this->userId, 'owner']);
        return $id;
    }

    private function ctx(int $cid): CompanyContext
    {
        return CompanyContext::forUser($this->db, $this->userId, $cid);
    }

    private function repo(int $cid): DocumentRepo
    {
        return new DocumentRepo($this->db, $this->ctx($cid));
    }

    private function svc(int $cid): DocumentService
    {
        $ctx = $this->ctx($cid);
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx));
    }

    private function customer(int $cid, string $name): int
    {
        return (new CustomerRepo($this->db, $this->ctx($cid)))->save(['name' => $name, 'country' => 'DE']);
    }

    /** @param list<array<string, mixed>> $lines */
    private function draft(int $cid, int $customer, array $lines = [], array $extra = [], string $type = 'invoice'): int
    {
        $lines = $lines ?: [['name' => 'A', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 100, 'discount_bp' => 0, 'vat_bp' => 1900]];
        return $this->svc($cid)->createDraft($type, ['customer_id' => $customer, 'lines' => $lines] + $extra);
    }

    private function at(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 10:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    public function test_find_returns_lines_in_order_and_decoded_snapshots(): void
    {
        $c = $this->customer($this->companyA, 'Kunde');
        $id = $this->draft($this->companyA, $c, [
            ['name' => 'Eins', 'unit' => 'hour', 'qty_milli' => 1500, 'unit_minor' => 2000, 'discount_bp' => 0, 'vat_bp' => 1900],
            ['name' => 'Zwei', 'unit' => 'day', 'qty_milli' => 1000, 'unit_minor' => 50000, 'discount_bp' => 500, 'vat_bp' => 700],
        ]);
        $draft = $this->repo($this->companyA)->find($id);
        $this->assertNull($draft['seller']);
        $this->assertNull($draft['buyer']);
        $this->assertSame(['Eins', 'Zwei'], array_column($draft['lines'], 'name'));
        $this->assertSame(3000, $draft['lines'][0]['net_minor']);
        $this->assertSame(47500, $draft['lines'][1]['net_minor']);
        $this->assertIsInt($draft['lines'][0]['qty_milli']);
        $this->assertIsInt($draft['net_minor']);

        $this->svc($this->companyA)->issue($id, $this->at('2026-11-24'));
        $doc = $this->repo($this->companyA)->find($id);
        $this->assertSame('Kunde', $doc['buyer']['name']);
        $this->assertSame('DE', $doc['buyer']['country']);
        $this->assertSame('A GmbH', $doc['seller']['name']);
        $this->assertSame(json_decode((string) $doc['buyer_json'], true), $doc['buyer']);
        foreach (['name', 'address', 'country', 'vat_id', 'is_company', 'email', 'vies_status', 'vies_checked_at', 'lang'] as $k) {
            $this->assertArrayHasKey($k, $doc['buyer']);
        }
        foreach (['name', 'address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'logo_path', 'intro_text', 'outro_text', 'footer_text'] as $k) {
            $this->assertArrayHasKey($k, $doc['seller']);
        }
    }

    public function test_list_filters_and_order(): void
    {
        $anna = $this->customer($this->companyA, "Anna O'Brien");
        $bert = $this->customer($this->companyA, 'Bert [x] #1 a--b');
        $i1 = $this->draft($this->companyA, $anna);
        $i2 = $this->draft($this->companyA, $bert);
        $o1 = $this->draft($this->companyA, $anna, [], [], 'offer');
        $d1 = $this->draft($this->companyA, $bert);
        $this->svc($this->companyA)->issue($i1, $this->at('2026-11-20'));
        $this->svc($this->companyA)->issue($i2, $this->at('2026-11-22'));
        $this->svc($this->companyA)->issue($o1, $this->at('2026-11-21'));
        $this->draft($this->companyB, $this->customer($this->companyB, 'Anna B'));

        $repo = $this->repo($this->companyA);
        // issue_date DESC, id DESC; drafts (no issue date) last
        $this->assertSame([$i2, $o1, $i1, $d1], array_column($repo->list(), 'id'));
        $this->assertSame(4, $repo->count());
        $this->assertSame([$i2, $i1, $d1], array_column($repo->list(['type' => 'invoice']), 'id'));
        $this->assertSame([$i2, $i1], array_column($repo->list(['type' => 'invoice', 'status' => 'issued']), 'id'));
        $this->assertSame([$d1], array_column($repo->list(['status' => 'draft']), 'id'));
        $this->assertSame([$o1, $i1], array_column($repo->list(['customer_id' => $anna]), 'id'));
        $this->assertSame(2, $repo->count(['customer_id' => $anna]));
        $this->assertSame([$i2], array_column($repo->list(['q' => 'RE-2026-002']), 'id'));
        $this->assertSame([$o1], array_column($repo->list(['q' => 'an-2026']), 'id'));
        $this->assertSame([$o1, $i1], array_column($repo->list(['q' => "o'brien"]), 'id'));
        $this->assertSame([$i2, $d1], array_column($repo->list(['q' => 'bert']), 'id'));
        $this->assertSame([$i2, $o1], array_column($repo->list([], 2), 'id'));
        $this->assertSame([$i1, $d1], array_column($repo->list([], 2, 2), 'id'));
        $this->assertSame([], $repo->list(['type' => 'bogus']));
        $this->assertSame([], $repo->list(['status' => 'paid']));
        $this->assertSame([], $repo->list(['customer_id' => $this->customer($this->companyB, 'X')]));
        $this->assertIsArray($repo->list()[0]['buyer']);
    }

    public function test_list_filter_by_several_types(): void
    {
        $anna = $this->customer($this->companyA, 'Anna');
        $i1 = $this->draft($this->companyA, $anna);
        $o1 = $this->draft($this->companyA, $anna, [], [], 'offer');
        $this->svc($this->companyA)->issue($i1, $this->at('2026-11-20'));
        $cn = $this->svc($this->companyA)->createCorrection($i1);
        $repo = $this->repo($this->companyA);
        $both = ['types' => ['invoice', 'credit_note']];
        $this->assertSame([$i1, $cn], array_column($repo->list($both), 'id'));
        $this->assertSame(2, $repo->count($both));
        $this->assertSame([$o1], array_column($repo->list(['types' => ['offer']]), 'id'));
        $this->assertSame([$i1], array_column($repo->list($both + ['status' => 'issued']), 'id'));
        $this->assertSame([$cn], array_column($repo->list(['related_document_id' => $i1]), 'id'));
        $this->assertSame([], $repo->list(['related_document_id' => $o1]));
        foreach ([[], ['invoice', 'bogus'], ['invoice', ['x']], 'invoice'] as $bad) {
            $this->assertSame([], $repo->list(['types' => $bad]), var_export($bad, true));
            $this->assertSame(0, $repo->count(['types' => $bad]));
        }
    }

    public function test_search_with_special_characters_never_throws(): void
    {
        $c = $this->customer($this->companyA, "o'brien a--b [x] #1 100% my_co");
        $id = $this->draft($this->companyA, $c);
        $repo = $this->repo($this->companyA);
        foreach (["o'brien", 'a--b', '[x]', '#1', '%', '_', '\\', '"', ';', '/*', '?1', 'UNION', ')', '(', "'"] as $q) {
            $rows = $repo->list(['q' => $q]);
            $this->assertIsArray($rows, $q);
            $this->assertIsInt($repo->count(['q' => $q]));
        }
        $this->assertSame([$id], array_column($repo->list(['q' => "O'BRIEN"]), 'id'));
        $this->assertSame([$id], array_column($repo->list(['q' => '[x]']), 'id'));
        $this->assertSame([$id], array_column($repo->list(['q' => '%']), 'id'), '% is stripped: matches everything');
    }

    public function test_other_company_documents_are_invisible(): void
    {
        $idB = $this->draft($this->companyB, $this->customer($this->companyB, 'Kunde B'));
        $repoA = $this->repo($this->companyA);
        $this->assertNull($repoA->find($idB));
        $this->assertSame([], $repoA->list(['q' => 'Kunde']));
        $this->assertSame(0, $repoA->count());
        $this->assertSame(1, $this->repo($this->companyB)->count());
    }
}
