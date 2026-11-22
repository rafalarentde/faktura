<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The single writer of audit_log (columns: company_id, user_id, action, detail JSON, at UTC).
 *
 * log() writes exactly one row and NEVER swallows a failure: called inside a caller's
 * transaction, a failed audit write rolls the state change back with it (the atomic-audit
 * rule). logSilently() is for informational events outside transactions (failed logins, logout):
 * it drops offending detail keys instead of throwing and, if the write itself fails, only
 * writes a short PII-free line to the error log.
 *
 * The detail may hold ids, flags, numbers and enumerated reason codes ONLY. Which keys an action may
 * carry is an allowlist (ALLOWED, one entry per existing action): an action that is not listed may
 * be logged without detail only, and an unlisted key is refused under any action whatever its
 * name (userEmail, mail, recipient, remote_addr ...). The guard rejects (log) or drops (logSilently):
 *  - unknown keys, non-string keys,
 *  - string values longer than 80 characters (190 for the two allowlisted free-text keys, see LIMITS),
 *  - string values that look like an e-mail address (@ followed by a dot), an IPv4/IPv6 address or an
 *    IBAN, except for the action+key pairs in VALUE_EXEMPT,
 *  - arrays/objects (except a list of field names under the keys `fields` or `changed`).
 * New events must be added to ALLOWED on purpose.
 *
 * Ids: a null company/user id is resolved by the closures App passes in (session user, active
 * company); Audit::NO_COMPANY forces a NULL company id. Without resolvers they stay NULL.
 * The detail is at most 2000 characters; a larger one loses keys from the end and gets
 * "truncated": true. Action names are lower-cased to [a-z0-9_.] (runs of other characters become one _),
 * at most 64 characters (the column width).
 */
final class Audit
{
    /** Pass as company id for an event that belongs to no company (instead of "resolve it"). */
    public const NO_COMPANY = 0;

    public const MAX_ACTION = 64;
    public const MAX_DETAIL = 2000;
    private const MAX_STRING = 80;
    /** Per action: the detail keys it may carry (ids, flags, counts, reason codes, field-name lists). */
    private const ALLOWED = [
        'auth.login_failed' => ['reason', 'key'],
        'auth.2fa_failed' => ['reason'],
        'auth.password_ok' => [],
        'auth.2fa_ok' => [],
        'auth.backup_code_used' => [],
        'auth.2fa_enabled' => [],
        'auth.logout' => [],
        'security.backup_codes_regenerate' => [],
        'security.password_change' => [],
        'company.switch' => ['company_id'],
        'export.create' => ['include_pdfs', 'year', 'doc_count', 'pdf_count'],
        'admin.company_create' => ['company_id', 'name', 'plan', 'status', 'until'],
        'admin.access_change' => ['company_id', 'old_status', 'new_status', 'old_until', 'new_until'],
        'admin.user_add' => ['company_id', 'user_id', 'email', 'role'],
        'admin.user_remove' => ['company_id', 'user_id'],
        'admin.user_reset_2fa' => ['company_id', 'user_id'],
        'admin.user_temp_password' => ['company_id', 'user_id'],
        'document.issue' => ['document_id', 'type', 'number', 'vat_confirmed'],
        'document.payment_add' => ['document_id', 'payment_id'],
        'document.correction_create' => ['document_id', 'invoice_id'],
        'document.offer_convert' => ['offer_id', 'invoice_id'],
        'document.offer_status' => ['document_id', 'from', 'to'],
        'document.dunning_create' => ['document_id', 'invoice_id', 'level', 'interest', 'flat_fee', 'fee'],
        'document.email_send' => ['document_id', 'status', 'reason'],
        'settings.mail_test' => ['status', 'reason'],
        'settings.numbers_save' => ['doc_type', 'fields'],
        'settings.company_save' => ['fields'],
        'settings.dunning_save' => ['fields'],
        'settings.mail_save' => ['fields'],
        'settings.logo_save' => ['fields'],
    ];
    /** action.key => longer string limit (company name and user e-mail are up to 190 characters). */
    private const LIMITS = [
        'admin.company_create.name' => 190,
        'admin.user_add.email' => 190,
    ];
    /** action.key pairs that may hold e-mail/IP/IBAN-looking text (the company name, the e-mail, a configurable number format). */
    private const VALUE_EXEMPT = [
        'admin.company_create.name',
        'admin.user_add.email',
        'document.issue.number',
    ];
    /** Keys whose value is a list of column/field NAMES (never values). */
    private const FIELD_LISTS = ['fields', 'changed'];
    /** auth.* rows older than this are removed by purgeAuth() (bin/purge-audit.php). */
    public const AUTH_RETENTION_DAYS = 90;

    private \Closure $clock;

    /**
     * @param \Closure|null $clock returns unix time (default time())
     * @param \Closure|null $userId returns the current user id or null
     * @param \Closure|null $companyId returns the active company id or null
     */
    public function __construct(
        private Db $db,
        ?\Closure $clock = null,
        private ?\Closure $userId = null,
        private ?\Closure $companyId = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param array<array-key, mixed> $detail ids, flags and reason codes only (see the class comment)
     * @param int|null $at unix time of the event (default: now)
     * @throws \InvalidArgumentException bad action name or a detail the guard refuses (nothing is written)
     * @throws \Throwable whatever the insert throws
     */
    public function log(string $action, array $detail = [], ?int $companyId = null, ?int $userId = null, ?int $at = null): void
    {
        $action = $this->normaliseAction($action);
        $this->guard($action, $detail);
        $this->write($action, $detail, $companyId, $userId, $at);
    }

    /**
     * Like log() for non-transactional informational events: never throws.
     *
     * @param array<array-key, mixed> $detail
     */
    public function logSilently(string $action, array $detail = [], ?int $companyId = null, ?int $userId = null): void
    {
        try {
            $action = $this->normaliseAction($action);
            $clean = [];
            foreach ($detail as $key => $value) {
                try {
                    $this->guard($action, [$key => $value]);
                    $clean[$key] = $value;
                } catch (\InvalidArgumentException) {
                    // dropped
                }
            }
            $this->write($action, $clean, $companyId, $userId, null);
        } catch (\Throwable $e) {
            error_log('faktura: audit write failed (' . $e::class . ')');
        }
    }

    private function normaliseAction(string $action): string
    {
        $action = substr((string) preg_replace('/[^a-z0-9_.]+/', '_', strtolower(trim($action))), 0, self::MAX_ACTION);
        if (trim($action, '_.') === '') {
            throw new \InvalidArgumentException('Audit action must not be empty.');
        }
        return $action;
    }

    /** @param array<array-key, mixed> $detail */
    private function guard(string $action, array $detail): void
    {
        $allowed = self::ALLOWED[$action] ?? [];
        foreach ($detail as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Audit detail keys must be non-empty strings.');
            }
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Audit detail key '$key' is not allowed for '$action'.");
            }
            $id = $action . '.' . $key;
            if (is_array($value) && in_array($key, self::FIELD_LISTS, true)) {
                if (count($value) > 60 || !array_is_list($value)) {
                    throw new \InvalidArgumentException("Audit detail '$key' must be a short list of field names.");
                }
                foreach ($value as $name) {
                    if (!is_string($name) || preg_match('/\A[a-z0-9_]{1,40}\z/', $name) !== 1) {
                        throw new \InvalidArgumentException("Audit detail '$key' may only list field names.");
                    }
                }
            } elseif (is_string($value)) {
                if (mb_strlen($value, 'UTF-8') > (self::LIMITS[$id] ?? self::MAX_STRING)) {
                    throw new \InvalidArgumentException("Audit detail '$key' is too long: ids and reason codes only.");
                }
                if (!in_array($id, self::VALUE_EXEMPT, true) && self::looksLikePii($value)) {
                    throw new \InvalidArgumentException("Audit detail '$key' looks like an e-mail address, IP address or IBAN.");
                }
            } elseif ($value !== null && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new \InvalidArgumentException("Audit detail '$key' must be a scalar.");
            }
        }
    }

    /** True for text that contains an e-mail address (an @ followed by a dot), an IPv4/IPv6 address or an IBAN. */
    private static function looksLikePii(string $value): bool
    {
        if (preg_match('/@.*\./s', $value) === 1) {
            return true;
        }
        if (preg_match('/(?<![0-9.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9])/', $value) === 1) {
            return true;
        }
        // IPv6: any run of hex digits, colons (and dots) holding at least two colons that parses as an address.
        if (preg_match_all('/[0-9a-f:.]*:[0-9a-f:.]*:[0-9a-f:.]*/i', $value, $m) > 0) {
            foreach ($m[0] as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    return true;
                }
            }
        }
        // IBAN: country code, two check digits and 11-30 alphanumerics, with or without spaces/hyphens.
        $compact = (string) preg_replace('/[\s-]+/', '', $value);
        return preg_match('/[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}/i', $compact) === 1
            && preg_match('/(?:\A|[^A-Z0-9])[A-Z]{2}[0-9]{2}(?:[\s-]?[A-Z0-9]){11,30}(?![A-Z0-9])/i', $value) === 1;
    }

    /**
     * Deletes auth.* rows older than $days (login, 2FA and logout events: the noisiest and the most
     * personal rows). Business events are kept. Returns the number of removed rows.
     */
    public function purgeAuth(int $days = self::AUTH_RETENTION_DAYS): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', ($this->clock)() - max(1, $days) * 86_400);
        return $this->db->exec("DELETE FROM audit_log WHERE action LIKE 'auth.%' AND at < ?", [$cutoff]);
    }

    /** @param array<string, mixed> $detail */
    private function write(string $action, array $detail, ?int $companyId, ?int $userId, ?int $at): void
    {
        if ($companyId === self::NO_COMPANY) {
            $companyId = null;
        } else {
            $companyId ??= $this->resolve($this->companyId);
        }
        $userId ??= $this->resolve($this->userId);
        $this->db->exec(
            'INSERT INTO audit_log (company_id, user_id, action, detail, at) VALUES (?, ?, ?, ?, ?)',
            [$companyId, $userId, $action, $this->encode($detail), gmdate('Y-m-d H:i:s', $at ?? ($this->clock)())]
        );
    }

    private function resolve(?\Closure $resolver): ?int
    {
        $id = $resolver === null ? null : $resolver();
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @param array<string, mixed> $detail */
    private function encode(array $detail): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $json = json_encode($detail === [] ? new \stdClass() : $detail, $flags);
        if (strlen($json) <= self::MAX_DETAIL) {
            return $json;
        }
        while ($detail !== []) {
            array_pop($detail);
            $json = json_encode($detail + ['truncated' => true], $flags);
            if (strlen($json) <= self::MAX_DETAIL) {
                return $json;
            }
        }
        return '{"truncated":true}';
    }
}
