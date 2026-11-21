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
 * The detail may hold ids, flags, numbers and enumerated reason codes ONLY. The guard rejects
 * (log) or drops (logSilently):
 *  - keys named like password|pass|secret|token|email|iban|bic|body|subject|to|address|name|ip
 *    (whole word between underscores, case-insensitive),
 *  - string values longer than 80 characters, arrays/objects (except a list of field names under
 *    the keys `fields` or `changed`), non-string keys.
 * A few events that predate the guard keep their stored shape (LEGACY_KEYS): an action may name
 * the keys it is allowed to use despite their name; the list is deliberately tiny and per action.
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
    private const FORBIDDEN_KEY = '/(?:^|_)(?:password|pass|passwd|secret|token|email|iban|bic|body|subject|to|address|name|ip)(?:_|$)/i';
    /** Keys whose value is a list of column/field NAMES (never values). */
    private const FIELD_LISTS = ['fields', 'changed'];
    /** Existing events whose detail legitimately carries a key the guard would refuse. */
    private const LEGACY_KEYS = [
        'admin.company_create' => ['name'],
        'admin.user_add' => ['email'],
        'document.offer_status' => ['to'],
    ];

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
        $allowed = self::LEGACY_KEYS[$action] ?? [];
        foreach ($detail as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Audit detail keys must be non-empty strings.');
            }
            if (!in_array($key, $allowed, true) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                throw new \InvalidArgumentException("Audit detail key '$key' is not allowed (possible PII or secret).");
            }
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
                if (mb_strlen($value, 'UTF-8') > self::MAX_STRING) {
                    throw new \InvalidArgumentException("Audit detail '$key' is too long: ids and reason codes only.");
                }
            } elseif ($value !== null && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new \InvalidArgumentException("Audit detail '$key' must be a scalar.");
            }
        }
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
