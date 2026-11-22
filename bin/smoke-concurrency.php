<?php
declare(strict_types=1);

/**
 * Concurrency smoke test for a REAL database (MySQL/MariaDB). Run it against an empty or throwaway
 * database after bin/migrate.php, never against production data:
 *
 *   FAKTURA_CONFIG=/path/to/config.php FAKTURA_SMOKE_CONFIRM=yes php bin/smoke-concurrency.php [invoices] [workers]
 *
 * It creates its own company ("SMOKE ...") with a customer, issues N invoices from W parallel PHP
 * processes and checks: every number unique, no gaps (1..N), no failures; then lets W processes pay
 * the same invoice at once and checks the net payment never exceeds the total. It removes its rows
 * afterwards when everything passed (issued documents are immutable by design, so the cleanup
 * deletes the SMOKE company's rows directly). Exit code 0 = all checks passed.
 */

use App\Core\Db;
use App\Core\DbFactory;
use App\Domain\DocumentService;
use App\Domain\NumberSequences;
use App\Repo\CustomerRepo;
use App\Repo\DocumentRepo;
use App\Tenant\CompanyContext;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../vendor/autoload.php';

function smokeService(Db $db, int $userId, int $companyId): DocumentService
{
    $ctx = CompanyContext::forUser($db, $userId, $companyId);
    $clock = static fn (): int => time();
    return new DocumentService($db, $ctx, new NumberSequences($db, $ctx, $clock), new DocumentRepo($db, $ctx), new CustomerRepo($db, $ctx), $clock);
}

$config = require (getenv('FAKTURA_CONFIG') ?: __DIR__ . '/../config/config.php');

// ---- worker mode: php smoke-concurrency.php worker <mode> <userId> <companyId> <ids,csv> ----
if (($argv[1] ?? '') === 'worker') {
    [, , $mode, $userId, $companyId, $ids] = $argv;
    $db = DbFactory::fromConfig($config);
    $svc = smokeService($db, (int) $userId, (int) $companyId);
    $out = [];
    foreach (explode(',', $ids) as $id) {
        try {
            if ($mode === 'issue') {
                $out[] = $db->retryOnLockError(fn (): string => $svc->issue((int) $id, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
            } else {
                $svc->addPayment((int) $id, 11900, new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')));
                $out[] = 'paid';
            }
        } catch (Throwable $e) {
            $out[] = 'ERR:' . get_class($e) . ':' . substr($e->getMessage(), 0, 120);
        }
    }
    echo json_encode($out), "\n";
    exit(0);
}

if (getenv('FAKTURA_SMOKE_CONFIRM') !== 'yes') {
    fwrite(STDERR, "Refusing to run: set FAKTURA_SMOKE_CONFIRM=yes (use an empty or throwaway database).\n");
    exit(2);
}
$n = max(2, (int) ($argv[1] ?? 40));
$w = max(2, (int) ($argv[2] ?? 8));
$db = DbFactory::fromConfig($config);
$tag = bin2hex(random_bytes(3));

$db->exec('INSERT INTO users (email, password_hash) VALUES (?, ?)', ["smoke-$tag@example.invalid", 'x']);
$userId = $db->lastId();
$db->exec(
    'INSERT INTO companies (name, address, tax_number, vat_id, iban, bic, bank, intro_text, outro_text, default_payment_days, access_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ["SMOKE $tag", "Hauptstr. 1\n10115 Berlin", '27/123/45678', 'DE123456789', 'DE89370400440532013000', 'COBADEFFXXX', 'Bank', '', '', 14, 'active']
);
$companyId = $db->lastId();
$db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $userId, 'owner']);
$svc = smokeService($db, $userId, $companyId);
$customer = (new CustomerRepo($db, CompanyContext::forUser($db, $userId, $companyId)))->save([
    'name' => 'Smoke Kunde', 'address' => "Kundenweg 1\n10115 Berlin", 'country' => 'DE', 'is_company' => '1', 'payment_days' => '30',
    'default_lang' => 'de', 'default_currency' => 'EUR',
]);
$line = ['name' => 'Beratung', 'unit' => 'hour', 'qty_milli' => 1000, 'unit_minor' => 10000, 'discount_bp' => 0, 'vat_bp' => 1900];

/** @param list<string> $args @return list<array<int,string>> */
function runWorkers(array $jobs): array
{
    $procs = [];
    foreach ($jobs as $args) {
        $cmd = array_merge([PHP_BINARY, __FILE__, 'worker'], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        $procs[] = [$p, $pipes];
    }
    $results = [];
    foreach ($procs as [$p, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($p);
        $decoded = json_decode(trim((string) $stdout), true);
        $results[] = is_array($decoded) ? $decoded : ['ERR:worker-crash:' . substr(trim($stderr . $stdout), 0, 200)];
    }
    return $results;
}

$failures = [];
$ids = [];
for ($i = 0; $i < $n; $i++) {
    $ids[] = $svc->createDraft('invoice', ['customer_id' => $customer, 'lines' => [$line]]);
}
$buckets = array_fill(0, $w, []);
foreach ($ids as $i => $id) {
    $buckets[$i % $w][] = $id;
}
$jobs = [];
foreach ($buckets as $b) {
    if ($b !== []) {
        $jobs[] = ['issue', (string) $userId, (string) $companyId, implode(',', $b)];
    }
}
$issued = [];
foreach (runWorkers($jobs) as $res) {
    foreach ($res as $r) {
        if (str_starts_with($r, 'ERR:')) {
            $failures[] = "issue: $r";
        } else {
            $issued[] = $r;
        }
    }
}
if (count($issued) !== count(array_unique($issued))) {
    $failures[] = 'duplicate document numbers: ' . implode(', ', array_diff_assoc($issued, array_unique($issued)));
}
if (count($issued) !== $n) {
    $failures[] = 'issued ' . count($issued) . " of $n";
}
$stored = array_column($db->all('SELECT number FROM documents WHERE company_id = ? AND number IS NOT NULL ORDER BY id', [$companyId]), 'number');
sort($stored, SORT_NATURAL);
echo 'numbers: ', $stored[0] ?? '-', ' .. ', $stored[count($stored) - 1] ?? '-', ' (', count($stored), " rows)\n";
$seq = [];
foreach ($stored as $num) {
    if (preg_match('/(\d+)\D*$/', (string) $num, $m)) {
        $seq[] = (int) $m[1];
    }
}
sort($seq);
if ($seq !== range(1, $n)) {
    $failures[] = 'numbering is not gap-free 1..' . $n . ': ' . implode(',', $seq);
}

// ---- payment race: W processes pay the same invoice (gross 119,00 EUR) simultaneously ----
$target = $ids[0];
$jobs = [];
for ($i = 0; $i < $w; $i++) {
    $jobs[] = ['pay', (string) $userId, (string) $companyId, (string) $target];
}
$paid = 0;
foreach (runWorkers($jobs) as $res) {
    foreach ($res as $r) {
        $paid += $r === 'paid' ? 1 : 0;
    }
}
$net = (int) $db->one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payments WHERE company_id = ? AND document_id = ?', [$companyId, $target])['s'];
$total = (int) $db->one('SELECT gross_minor AS g FROM documents WHERE id = ? AND company_id = ?', [$target, $companyId])['g'];
echo "payments accepted: $paid, net $net of total $total\n";
if ($net > $total || $paid !== 1) {
    $failures[] = "payment race: $paid accepted, net $net vs total $total";
}

if ($failures === []) {
    // Cleanup: the SMOKE company only (children first; the schema has no foreign keys by design).
    foreach (['payments', 'document_lines', 'email_log', 'document_archives', 'number_counters', 'audit_log', 'documents', 'customers', 'number_formats', 'company_settings', 'company_users'] as $t) {
        try {
            $db->exec("DELETE FROM `$t` WHERE company_id = ?", [$companyId]);
        } catch (Throwable) {
            // table without company_id or not present: ignore
        }
    }
    $db->exec('DELETE FROM companies WHERE id = ?', [$companyId]);
    $db->exec('DELETE FROM users WHERE id = ?', [$userId]);
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $failures) . "\n(left company id $companyId for inspection)\n");
exit(1);
