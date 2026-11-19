<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;

/** App::documents() builds the service for the logged-in user's company and issues end to end. */
final class DocumentsAppTest extends HttpTestCase
{
    public function test_documents_service_through_app_issues_a_document(): void
    {
        $user = $this->createUser();
        $this->db->exec("INSERT INTO companies (name, default_payment_days) VALUES ('App GmbH', 10)");
        $companyId = $this->db->lastId();
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $user['id'], 'owner']);
        $this->loginFully($user['secret']);
        $this->app->resetContext();

        $ctx = $this->app->context();
        $this->assertNotNull($ctx);
        $svc = $this->app->documents();
        $this->assertSame($svc, $this->app->documents(), 'cached per request');
        $customer = (new CustomerRepo($this->db, $ctx))->save(['name' => 'Kunde', 'country' => 'DE']);
        $id = $svc->createDraft('invoice', ['customer_id' => $customer, 'lines' => [
            ['name' => 'Beratung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 10000, 'discount_bp' => 0, 'vat_bp' => 1900],
        ]]);
        // now = 1_700_000_010 = 2023-11-14 (Berlin): the App clock drives dates and the number year
        $this->assertSame('RE-2023-001', $svc->issue($id, new \DateTimeImmutable('@' . $this->now)));
        $doc = (new DocumentRepo($this->db, $ctx))->find($id);
        $this->assertSame('issued', $doc['status']);
        $this->assertSame($companyId, $doc['company_id']);
        $this->assertSame('2023-11-24', $doc['due_date']);
        $this->assertSame(11900, $svc->balanceMinor($id));

        $this->app->resetContext();
        $this->assertNotSame($svc, $this->app->documents(), 'reset with the context');
    }

    public function test_documents_without_a_company_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);
        $this->app->documents();
    }
}
