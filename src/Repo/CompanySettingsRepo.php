<?php
declare(strict_types=1);

namespace App\Repo;

use App\Core\Crypto;
use App\Core\Audit;
use App\Core\Db;
use App\Domain\Iban;
use App\Domain\Percent;
use App\Domain\ValidationException;
use App\Domain\VatId;
use App\Mail\SmtpTargetPolicy;
use App\Money\AmountParser;
use App\Tenant\CompanyContext;

/**
 * Settings of the active company: company data, logo reference, dunning rules and SMTP.
 *
 * NOT a BaseRepo: the `companies` table has no company_id column, the company IS the row.
 * Isolation instead comes from the constructor: every SELECT/UPDATE here is `WHERE id = ?`
 * bound to CompanyContext::id() and never to an id from a request (a `id`/`company_id` field in
 * the input is ignored because only the whitelisted fields below are read).
 *
 * Validation failures throw ValidationException (field => translation key); nothing is written.
 * Every save also writes an audit_log row in the same transaction. The detail lists the NAMES of
 * the changed fields only, never values, so secrets cannot leak into the log.
 *
 * The SMTP password is encrypted with Crypto before it is stored and is never returned: get()
 * exposes only `smtp_pass_set`. A blank password input keeps the stored one.
 */
final class CompanySettingsRepo
{
    public const LANGS = ['de', 'pl', 'en'];
    public const CURRENCIES = ['EUR', 'PLN', 'USD'];
    private const COMPANY_TEXT_FIELDS = ['intro_text', 'outro_text', 'footer_text'];
    private const MAX_FEE_MINOR = 100000; // 1000 EUR

    private \Closure $clock;
    private ?Audit $audit = null;

    public function __construct(
        private Db $db,
        private CompanyContext $ctx,
        private ?Crypto $crypto = null,
        ?\Closure $clock = null,
        private ?SmtpTargetPolicy $policy = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * The company row without the encrypted SMTP password, plus `smtp_pass_set` (bool).
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $row = $this->row();
        $row['smtp_pass_set'] = ($row['smtp_pass_enc'] ?? null) !== null && $row['smtp_pass_enc'] !== '';
        unset($row['smtp_pass_enc']);
        return $row;
    }

    /** True when host, port, user, password and from address are all set (the mail tab's status chip). */
    public function mailConfigured(): bool
    {
        $s = $this->get();
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from_addr'] as $f) {
            if ($s[$f] === null || $s[$f] === '') {
                return false;
            }
        }
        return $s['smtp_pass_set'];
    }

    /**
     * The SMTP settings with the password DECRYPTED, for the mailer only (call it right before
     * sending; never render, log or store the result). Null when the settings are incomplete or
     * the stored password cannot be decrypted.
     *
     * @return array{host: string, port: int, user: string, pass: string, from_addr: string, from_name: string}|null
     */
    public function smtpCredentials(): ?array
    {
        if (!$this->mailConfigured() || $this->crypto === null) {
            return null;
        }
        $row = $this->row();
        try {
            $pass = $this->crypto->decrypt((string) $row['smtp_pass_enc']);
        } catch (\Throwable) {
            return null;
        }
        if ($pass === '') {
            return null;
        }
        return [
            'host' => (string) $row['smtp_host'], 'port' => (int) $row['smtp_port'], 'user' => (string) $row['smtp_user'],
            'pass' => $pass, 'from_addr' => (string) $row['smtp_from_addr'], 'from_name' => (string) ($row['smtp_from_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $in name, address, tax_number, vat_id, iban, bic, bank,
     *        default_lang, default_currency, default_payment_days, intro_text, outro_text, footer_text
     * @throws ValidationException
     */
    public function saveCompany(array $in): void
    {
        $e = [];
        $name = trim($this->str($in, 'name'));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
            $e['name'] = 'settings.err_name';
        }
        $address = trim($this->str($in, 'address'));
        if (mb_strlen($address, 'UTF-8') > 500) {
            $e['address'] = 'settings.err_address';
        }
        $tax = trim($this->str($in, 'tax_number'));
        if (mb_strlen($tax, 'UTF-8') > 40) {
            $e['tax_number'] = 'settings.err_tax_number';
        }
        $vat = $this->vatId($this->str($in, 'vat_id'));
        if ($vat === false) {
            $e['vat_id'] = 'settings.err_vat_id';
        }
        $iban = Iban::normalize($this->str($in, 'iban'));
        if ($iban !== '' && !Iban::isValid($iban)) {
            $e['iban'] = 'settings.err_iban';
        }
        $bic = strtoupper(preg_replace('/\s+/u', '', $this->str($in, 'bic')) ?? '');
        if ($bic !== '' && preg_match('/\A[A-Z0-9]{8}(?:[A-Z0-9]{3})?\z/', $bic) !== 1) {
            $e['bic'] = 'settings.err_bic';
        }
        $bank = trim($this->str($in, 'bank'));
        if (mb_strlen($bank, 'UTF-8') > 190) {
            $e['bank'] = 'settings.err_bank';
        }
        $lang = $this->str($in, 'default_lang');
        if (!in_array($lang, self::LANGS, true)) {
            $e['default_lang'] = 'settings.err_lang';
        }
        $currency = $this->str($in, 'default_currency');
        if (!in_array($currency, self::CURRENCIES, true)) {
            $e['default_currency'] = 'settings.err_currency';
        }
        $days = trim($this->str($in, 'default_payment_days'));
        if (preg_match('/\A[0-9]{1,3}\z/', $days) !== 1 || (int) $days > 365) {
            $e['default_payment_days'] = 'settings.err_payment_days';
        }
        $texts = [];
        foreach (self::COMPANY_TEXT_FIELDS as $f) {
            $texts[$f] = trim($this->str($in, $f));
            if (mb_strlen($texts[$f], 'UTF-8') > 2000) {
                $e[$f] = 'settings.err_text';
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->update('settings.company_save', [
            'name' => $name,
            'address' => $this->nullIfEmpty($address),
            'tax_number' => $this->nullIfEmpty($tax),
            'vat_id' => $vat,
            'iban' => $this->nullIfEmpty($iban),
            'bic' => $this->nullIfEmpty($bic),
            'bank' => $this->nullIfEmpty($bank),
            'default_lang' => $lang,
            'default_currency' => $currency,
            'default_payment_days' => (int) $days,
            'intro_text' => $this->nullIfEmpty($texts['intro_text']),
            'outro_text' => $this->nullIfEmpty($texts['outro_text']),
            'footer_text' => $this->nullIfEmpty($texts['footer_text']),
        ]);
    }

    /**
     * @param array<string, string> $in dunning_d0..d3, dunning_base_rate (percent, empty = NULL),
     *        dunning_b2b, dunning_b2c (percentage points), dunning_flat, dunning_fee (amounts)
     * @param string $lang decides how percentages and amounts are read ("1,27" de/pl, "1.27" en)
     * @throws ValidationException
     */
    public function saveDunning(array $in, string $lang = 'de'): void
    {
        $e = [];
        $days = [];
        foreach (['dunning_d0', 'dunning_d1', 'dunning_d2', 'dunning_d3'] as $i => $f) {
            $raw = trim($this->str($in, $f));
            if (preg_match('/\A[0-9]{1,3}\z/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > 365) {
                $e[$f] = 'settings.err_dunning_days';
                continue;
            }
            $days[$f] = (int) $raw;
            $prev = $i === 0 ? null : ($days['dunning_d' . ($i - 1)] ?? null);
            if ($prev !== null && $days[$f] <= $prev) {
                $e[$f] = 'settings.err_dunning_order';
            }
        }
        $base = null;
        $rawBase = trim($this->str($in, 'dunning_base_rate'));
        if ($rawBase !== '') {
            $base = $this->percent($rawBase, 3000);
            if ($base === null) {
                $e['dunning_base_rate'] = 'settings.err_base_rate';
            }
        }
        $b2b = $this->percent($this->str($in, 'dunning_b2b'), 2000);
        if ($b2b === null) {
            $e['dunning_b2b'] = 'settings.err_surcharge';
        }
        $b2c = $this->percent($this->str($in, 'dunning_b2c'), 2000);
        if ($b2c === null) {
            $e['dunning_b2c'] = 'settings.err_surcharge';
        }
        $flat = $this->amount($this->str($in, 'dunning_flat'), $lang, false);
        if ($flat === null) {
            $e['dunning_flat'] = 'settings.err_amount';
        }
        $fee = $this->amount($this->str($in, 'dunning_fee'), $lang, true);
        if ($fee === null) {
            $e['dunning_fee'] = 'settings.err_amount';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->update('settings.dunning_save', [
            'dunning_d0' => $days['dunning_d0'],
            'dunning_d1' => $days['dunning_d1'],
            'dunning_d2' => $days['dunning_d2'],
            'dunning_d3' => $days['dunning_d3'],
            'dunning_base_rate_bp' => $base,
            'dunning_b2b_surcharge_bp' => $b2b,
            'dunning_b2c_surcharge_bp' => $b2c,
            'dunning_flat_minor' => $flat,
            'dunning_fee_minor' => $fee,
        ]);
    }

    /**
     * All fields optional. A blank `smtp_pass` keeps the stored password; a non-blank one is
     * encrypted before storing. Port 465 means implicit TLS, any other port STARTTLS (see
     * App\Mail\SmtpSecurity); there is no plaintext option.
     *
     * @param array<string, string> $in smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from_name, smtp_from_addr
     * @throws ValidationException
     */
    public function saveMail(array $in): void
    {
        $e = [];
        $host = trim($this->str($in, 'smtp_host'));
        if ($host !== '' && !$this->validHost($host)) {
            $e['smtp_host'] = 'settings.err_smtp_host';
        }
        $port = trim($this->str($in, 'smtp_port'));
        if ($port !== '' && (preg_match('/\A[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535)) {
            $e['smtp_port'] = 'settings.err_smtp_port';
        }
        // SSRF guard (friendly message here; the mailer enforces it again at send time).
        if ($this->policy !== null) {
            if (!isset($e['smtp_port']) && $port !== '' && !SmtpTargetPolicy::portAllowed((int) $port)) {
                $e['smtp_port'] = 'settings.err_smtp_port_list';
            }
            if (!isset($e['smtp_host']) && $host !== '' && $this->policy->connectAddress($host) === null) {
                $e['smtp_host'] = 'settings.err_smtp_host_unsafe';
            }
        }
        $user = trim($this->str($in, 'smtp_user'));
        if (mb_strlen($user, 'UTF-8') > 190) {
            $e['smtp_user'] = 'settings.err_smtp_user';
        }
        $pass = $this->str($in, 'smtp_pass');
        if (strlen($pass) > 1024) {
            $e['smtp_pass'] = 'settings.err_smtp_pass';
        }
        $fromName = trim($this->str($in, 'smtp_from_name'));
        if (mb_strlen($fromName, 'UTF-8') > 190) {
            $e['smtp_from_name'] = 'settings.err_smtp_from_name';
        }
        $fromAddr = trim($this->str($in, 'smtp_from_addr'));
        if ($fromAddr !== '' && (filter_var($fromAddr, FILTER_VALIDATE_EMAIL) === false || strlen($fromAddr) > 190)) {
            $e['smtp_from_addr'] = 'settings.err_smtp_from_addr';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $values = [
            'smtp_host' => $this->nullIfEmpty($host),
            'smtp_port' => $port === '' ? null : (int) $port,
            'smtp_user' => $this->nullIfEmpty($user),
            'smtp_from_name' => $this->nullIfEmpty($fromName),
            'smtp_from_addr' => $this->nullIfEmpty($fromAddr),
        ];
        if ($pass !== '') {
            $crypto = $this->crypto ?? throw new \LogicException('Crypto is required to store an SMTP password.');
            $values['smtp_pass_enc'] = $crypto->encrypt($pass);
        }
        $this->update('settings.mail_save', $values);
    }

    /** Records the stored logo (path relative to the storage directory) or clears it with NULL. */
    public function setLogo(?string $path): void
    {
        $this->update('settings.logo_save', ['logo_path' => $path]);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = ?', [$this->ctx->id()])
            ?? throw new \RuntimeException('Company not found.');
    }

    /**
     * Writes the changed columns of the context company and an audit row, atomically.
     * The audit detail names the changed fields, never their values.
     *
     * @param array<string, scalar|null> $values column => new value
     */
    private function update(string $action, array $values): void
    {
        $this->db->transaction(function () use ($action, $values): void {
            $old = $this->row();
            $changed = [];
            foreach ($values as $col => $new) {
                $was = ($old[$col] ?? null) === null ? null : (string) $old[$col];
                $now = $new === null ? null : (string) $new;
                if ($was !== $now) {
                    $changed[] = $col === 'smtp_pass_enc' ? 'smtp_pass' : $col;
                }
            }
            $sets = implode(', ', array_map(fn (string $c): string => "`$c` = ?", array_keys($values)));
            $this->db->exec("UPDATE companies SET $sets WHERE id = ?", [...array_values($values), $this->ctx->id()]);
            $this->audit ??= new Audit($this->db, $this->clock);
            $this->audit->log($action, ['fields' => $changed], $this->ctx->id(), $this->ctx->userId());
        });
    }

    /** @param array<string, mixed> $in */
    private function str(array $in, string $key): string
    {
        $v = $in[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    private function nullIfEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    /** @return string|null|false normalised VAT id, null when empty, false when invalid */
    private function vatId(string $raw): string|null|false
    {
        $compact = strtoupper(preg_replace('/[\s.\-]+/u', '', $raw) ?? '');
        if ($compact === '') {
            return null;
        }
        $country = 'DE';
        $prefix = substr($compact, 0, 2);
        if ($prefix === 'EL') {
            $country = 'GR';
        } elseif (preg_match('/\A[A-Z]{2}\z/', $prefix) === 1 && \App\Domain\Countries::isEu($prefix)) {
            $country = $prefix;
        }
        $vat = VatId::normalize($country, $compact);
        if ($vat === null || preg_match('/\A[A-Z]{2}[A-Z0-9+*]{2,12}\z/', $vat) !== 1) {
            return false;
        }
        return $vat;
    }

    /** Basis points of a typed percentage within 0..$maxBp, or null when invalid. */
    private function percent(string $raw, int $maxBp): ?int
    {
        try {
            $bp = Percent::parseBp($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $bp <= $maxBp ? $bp : null;
    }

    /** Minor units of a typed amount, 0..1000 EUR; null when invalid (or empty and not allowed). */
    private function amount(string $raw, string $lang, bool $emptyIsZero): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $emptyIsZero ? 0 : null;
        }
        try {
            $minor = AmountParser::parse($raw, $lang);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $minor <= self::MAX_FEE_MINOR ? $minor : null;
    }

    private function validHost(string $host): bool
    {
        if (strlen($host) > 190) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/', $host) === 1;
    }
}
