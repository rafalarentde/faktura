<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Auth\UserService;
use App\Core\Crypto;
use App\Core\Db;
use App\Domain\DocumentService;
use App\Domain\DunningLevel;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Repo\ItemRepo;
use App\Tenant\CompanyContext;

/**
 * Data for the export tests: companies with members (password hash, TOTP secret and backup codes
 * planted), customers, items, a draft, issued invoices (2026 and 2025), a credit note, an offer,
 * a dunning letter, payments, an email log row, all created through the real services. Every
 * visible string of a company carries its tag (e.g. ALPHA, ZETA) so a leak is a plain substring.
 */
final class ExportFixture
{
    public const SMTP_PASSWORD = 'Pl4intext-SMTP-Passw0rd';
    public const USER_PASSWORD = 'correct horse battery';

    public function __construct(public Db $db, public int $now)
    {
    }

    public function crypto(): Crypto
    {
        return new Crypto(base64_encode(str_repeat('k', 32)));
    }

    private static ?string $hash = null;

    /**
     * A member with a real Argon2id hash (computed once per process: hashing is slow), an encrypted
     * TOTP secret, a replay step and eight backup codes (cheap hashes) so the tests can scan for them.
     *
     * @return array{id: int, backup_codes: list<string>}
     */
    public function user(string $email): array
    {
        self::$hash ??= password_hash(self::USER_PASSWORD, PASSWORD_ARGON2ID);
        $this->db->exec(
            'INSERT INTO users (email, password_hash, totp_enabled, totp_last_step) VALUES (?, ?, 1, 123456)',
            [UserService::normaliseEmail($email), self::$hash]
        );
        $id = $this->db->lastId();
        $this->db->exec('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [$this->crypto()->encrypt('TOTPSECRETPLAINTEXT' . $id), $id]);
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
            $codes[] = $code;
            $this->db->exec('INSERT INTO backup_codes (user_id, code_hash) VALUES (?, ?)', [$id, password_hash($code, PASSWORD_BCRYPT, ['cost' => 4])]);
        }
        return ['id' => $id, 'backup_codes' => $codes];
    }

    public function company(string $name, string $status = 'active', string $tag = 'ALPHA'): int
    {
        $this->db->exec(
            'INSERT INTO companies (name, address, tax_number, vat_id, iban, bic, bank, access_status, plan, smtp_host, smtp_port, smtp_user, smtp_pass_enc, smtp_from_name, smtp_from_addr, dunning_base_rate_bp)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 127)',
            [$name, "Musterweg 1\n10115 Berlin", '27/123/45678', 'DE987654321', 'DE89370400440532013000', 'COBADEFFXXX', "Bank $tag", $status, 'standard',
                'smtp.' . strtolower($tag) . '.example', 587, 'user@' . strtolower($tag) . '.example', $this->crypto()->encrypt(self::SMTP_PASSWORD . $tag),
                "Absender $tag", 'rechnung@' . strtolower($tag) . '.example']
        );
        return $this->db->lastId();
    }

    public function member(int $userId, int $companyId, string $role = 'owner'): void
    {
        $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $userId, $role]);
    }

    public function ctx(int $companyId, int $userId): CompanyContext
    {
        return CompanyContext::forUser($this->db, $userId, $companyId);
    }

    public function service(int $companyId, int $userId): DocumentService
    {
        $ctx = $this->ctx($companyId, $userId);
        $clock = fn (): int => $this->now;
        return new DocumentService($this->db, $ctx, new NumberSequences($this->db, $ctx, $clock), new DocumentRepo($this->db, $ctx), new CustomerRepo($this->db, $ctx), $clock);
    }

    /** @param array<string, mixed> $data */
    public function customer(int $companyId, int $userId, string $name, string $email, array $data = []): int
    {
        return (new CustomerRepo($this->db, $this->ctx($companyId, $userId)))->save($data + [
            'name' => $name, 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => '1', 'payment_days' => '14',
            'default_lang' => 'de', 'default_currency' => 'EUR', 'email' => $email,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function lines(string $tag, int $net = 120000): array
    {
        return [['name' => "Beratung $tag", 'description' => "Leistung $tag", 'unit' => 'flat', 'qty_milli' => 1000, 'unit_minor' => $net, 'discount_bp' => 0, 'vat_bp' => 1900]];
    }

    /**
     * Fills one company. Invoice 1: 1.428,00 gross, issued 2026-10-14, due 2026-10-28, partly paid,
     * dunned, one credit note over a part. Invoice 2: issued in 2025. Plus a draft and an offer.
     *
     * @return array<string, int|string>
     */
    public function populate(int $companyId, int $ownerId, string $tag): array
    {
        $svc = $this->service($companyId, $ownerId);
        $at = new \DateTimeImmutable('@' . $this->now);
        $customer = $this->customer($companyId, $ownerId, "Kunde $tag GmbH", 'kunde-' . strtolower($tag) . '@example.com');
        $items = new ItemRepo($this->db, $this->ctx($companyId, $ownerId));
        $item = $items->save(['name_de' => "Artikel $tag", 'name_pl' => "Towar $tag", 'name_en' => "Item $tag", 'description_de' => "Beschreibung $tag", 'type' => 'service', 'unit' => 'hour',
            'price' => '100,00', 'currency' => 'EUR', 'vat_bp' => 1900]);

        $invoice = $svc->createDraft('invoice', ['customer_id' => $customer, 'issue_date' => '2026-10-14', 'due_date' => '2026-10-28', 'lines' => self::lines($tag)]);
        $svc->issue($invoice, $at);
        $svc->addPayment($invoice, 50000, new \DateTimeImmutable('2026-10-20'), "Anzahlung $tag");
        $old = $svc->createDraft('invoice', ['customer_id' => $customer, 'issue_date' => '2025-03-10', 'due_date' => '2025-03-24', 'lines' => self::lines($tag, 5000)]);
        $svc->issue($old, $at);
        $credit = $svc->createCorrection($old);
        $svc->issue($credit, $at);
        $dunning = $svc->createDunning($invoice, DunningLevel::First, true, true, false);
        $offer = $svc->createDraft('offer', ['customer_id' => $customer, 'issue_date' => '2026-11-01', 'valid_until' => '2026-12-31', 'lines' => self::lines($tag, 30000)]);
        $svc->issue($offer, $at);
        $draft = $svc->createDraft('invoice', ['customer_id' => $customer, 'issue_date' => '2026-11-17', 'due_date' => '2026-12-01', 'notes' => "Entwurf $tag", 'lines' => self::lines($tag, 7700)]);
        $this->db->exec(
            "INSERT INTO email_log (company_id, document_id, to_email, subject, status, sent_by, created_at) VALUES (?, ?, ?, ?, 'sent', ?, '2026-10-15 08:00:00')",
            [$companyId, $invoice, 'kunde-' . strtolower($tag) . '@example.com', "Rechnung $tag", $ownerId]
        );
        return ['customer' => $customer, 'item' => $item, 'invoice' => $invoice, 'old_invoice' => $old, 'credit' => $credit, 'dunning' => $dunning, 'offer' => $offer, 'draft' => $draft, 'tag' => $tag];
    }
}
