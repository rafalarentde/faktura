<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Db;

/**
 * The staged login: password (rate limited per email and per IP), then a TOTP or backup
 * code (rate limited per user), plus first-time TOTP setup. Session changes are left to
 * the caller; this class only answers "may this step pass?".
 */
final class AuthService
{
    /** Longer input is rejected outright (after the same amount of work). */
    public const MAX_PASSWORD_BYTES = 1024;

    /** Hash of a random string, verified when the email is unknown so both cases cost the same. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$S1V0aHh3WFdjSFdtQ1F5TQ$YrMAj7G/+/yjIpBGCWwaMsaZxE2DoFRZ3tdwp6Qs9/A';

    /** Seconds in which a locked login/2FA writes at most one audit row per key. */
    private const LOCKED_LOG_WINDOW = 900;

    private \Closure $clock;
    private RateLimiter $lockedLog;

    public function __construct(
        private Db $db,
        private RateLimiter $limiter,
        private Totp $totp,
        private BackupCodes $backupCodes,
        private Crypto $crypto,
        ?\Closure $clock = null,
        private ?Audit $audit = null,
        /** Secret for the HMAC of the audit key (config `audit_key`); null derives one from the app key. */
        private ?string $auditKey = null,
    ) {
        $this->clock = $clock ?? time(...);
        // At most one "locked" audit row per key and window, however often a locked account is tried.
        $this->lockedLog = new RateLimiter($db, 1, self::LOCKED_LOG_WINDOW, $this->clock);
    }

    public function attemptPassword(string $email, string $password, string $ip): AuthResult
    {
        $email = UserService::normaliseEmail($email);
        $keys = $this->loginKeys($email, $ip);
        // Hit first (RateLimiter::attempt): every guess is recorded before its password is verified, so
        // concurrent guesses cannot all slip under the limit. Every key is hit, none is short-circuited.
        $allowed = true;
        foreach ($keys as $key) {
            $allowed = $this->limiter->attempt($key) && $allowed;
        }
        if (!$allowed) {
            $this->auditLocked('login:' . $this->emailKey($email), 'auth.login_failed', ['reason' => 'locked', 'key' => $this->emailKey($email)]);
            return AuthResult::locked();
        }

        $user = strlen($email) <= 190
            ? $this->db->one('SELECT id, password_hash, totp_enabled FROM users WHERE email = ?', [$email])
            : null;
        $tooLong = strlen($password) > self::MAX_PASSWORD_BYTES;
        $hash = $user !== null ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $ok = password_verify($tooLong ? '' : $password, $hash) && $user !== null && !$tooLong;

        if (!$ok) {
            $this->audit?->logSilently(
                'auth.login_failed',
                ['reason' => $user === null ? 'unknown_user' : 'bad_password', 'key' => $this->emailKey($email)],
                Audit::NO_COMPANY
            );
            return AuthResult::invalid();
        }
        $id = (int) $user['id'];
        // Only the per-email counter is cleared here: the IP counter is cleared after the second factor
        // (an attacker with one valid account must not reset the IP budget by logging in).
        $this->limiter->clear($keys[0]);
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $this->db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $id]);
        }
        $this->audit?->log('auth.password_ok', [], Audit::NO_COMPANY, $id);
        return AuthResult::ok($id, (int) $user['totp_enabled'] === 1);
    }

    /** Second step: a 6-digit TOTP code (replay-protected) or a backup code. */
    public function verifySecondFactor(int $userId, string $code, string $ip): AuthResult
    {
        $key = $this->secondFactorKey($userId);
        if (!$this->limiter->attempt($key)) {
            $this->auditLocked('2fa:' . $userId, 'auth.2fa_failed', ['reason' => 'locked'], $userId);
            return AuthResult::locked();
        }
        $user = $this->db->one('SELECT id, email, totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$userId]);
        $code = trim($code);
        $ok = false;
        $backup = false;
        if ($user !== null && (int) $user['totp_enabled'] === 1) {
            $backup = preg_match('/\A[0-9]{3} ?[0-9]{3}\z/', $code) !== 1;
            $ok = $backup
                ? $this->backupCodes->consume($userId, $code)
                : $this->acceptTotp($userId, $this->secret($user), $code);
        }
        if (!$ok) {
            $this->audit?->logSilently('auth.2fa_failed', ['reason' => 'invalid'], Audit::NO_COMPANY, $userId);
            return AuthResult::invalid();
        }
        // The code is already spent: an audit failure must not turn this into a 500 (hence logSilently).
        $this->audit?->logSilently('auth.2fa_ok', [], Audit::NO_COMPANY, $userId);
        if ($backup) {
            $this->audit?->logSilently('auth.backup_code_used', [], Audit::NO_COMPANY, $userId);
        }
        $this->limiter->clear($key);
        foreach ($this->loginKeys((string) $user['email'], $ip) as $k) {
            $this->limiter->clear($k);
        }
        return AuthResult::ok($userId);
    }

    /** Lifts the login lockout of this address (admin temporary password); the IP counter is left alone. */
    public function clearLoginLock(string $email): void
    {
        $this->limiter->clear($this->loginKeys(UserService::normaliseEmail($email), '')[0]);
    }

    /** The not-yet-confirmed TOTP secret, created and stored encrypted on first call. */
    public function pendingTotpSecret(int $userId): string
    {
        $user = $this->db->one('SELECT totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$userId]);
        if ($user === null || (int) $user['totp_enabled'] === 1) {
            throw new \LogicException('No pending TOTP setup for this user.');
        }
        if ($user['totp_secret_enc'] !== null && $user['totp_secret_enc'] !== '') {
            return $this->secret($user);
        }
        $secret = $this->totp->newSecret();
        $this->db->exec(
            'UPDATE users SET totp_secret_enc = ? WHERE id = ? AND totp_enabled = 0',
            [$this->crypto->encrypt($secret), $userId]
        );
        return $secret;
    }

    /** Confirms the pending secret with a valid code; on success the result carries 8 backup codes (show once). */
    public function confirmTotpSetup(int $userId, string $code): AuthResult
    {
        $key = $this->secondFactorKey($userId);
        if (!$this->limiter->attempt($key)) {
            return AuthResult::locked();
        }
        $user = $this->db->one('SELECT totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$userId]);
        $ok = $user !== null
            && (int) $user['totp_enabled'] === 0
            && !empty($user['totp_secret_enc'])
            && $this->acceptTotp($userId, $this->secret($user), trim($code));
        if (!$ok) {
            return AuthResult::invalid();
        }
        $codes = $this->db->transaction(function (Db $db) use ($userId): array {
            $db->exec('UPDATE users SET totp_enabled = 1, session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
            $this->audit?->log('auth.2fa_enabled', [], Audit::NO_COMPANY, $userId);
            return $this->backupCodes->generate($userId);
        });
        $this->limiter->clear($key);
        return AuthResult::ok($userId, true, $codes);
    }

    /** Accepts the code only if its step is newer than the last accepted one, then records it. */
    private function acceptTotp(int $userId, string $secret, string $code): bool
    {
        $step = $this->totp->matchedStep($secret, $code, ($this->clock)());
        if ($step === null) {
            return false;
        }
        // Conditional update: two concurrent requests with the same code cannot both win.
        return $this->db->exec(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $userId, $step]
        ) === 1;
    }

    /** @param array<string, mixed> $user */
    private function secret(array $user): string
    {
        return $this->crypto->decrypt((string) $user['totp_secret_enc']);
    }

    /** @return list<string> */
    private function loginKeys(string $email, string $ip): array
    {
        return ['login:email:' . $email, 'login:ip:' . $ip];
    }

    /**
     * First 12 hex chars of HMAC-SHA256(email) under a secret: lets the audit log show brute-force
     * patterns without storing the address, and without a plain hash that anyone could test a list of
     * known addresses against.
     */
    private function emailKey(string $email): string
    {
        $secret = $this->auditKey !== null && $this->auditKey !== '' ? $this->auditKey : $this->crypto->deriveKey('faktura/audit/login-key');
        return substr(hash_hmac('sha256', strtolower($email), $secret), 0, 12);
    }

    /** Writes a "locked" audit row unless one was already written for $scope in the last window. @param array<string, mixed> $detail */
    private function auditLocked(string $scope, string $action, array $detail, ?int $userId = null): void
    {
        if ($this->audit === null) {
            return;
        }
        $key = 'audit:locked:' . $scope;
        try {
            if ($this->lockedLog->tooMany($key)) {
                return;
            }
            $this->lockedLog->hit($key);
        } catch (\Throwable) {
            return; // the marker is best effort; never turn a locked login into a 500
        }
        $this->audit->logSilently($action, $detail, Audit::NO_COMPANY, $userId);
    }

    private function secondFactorKey(int $userId): string
    {
        return '2fa:user:' . $userId;
    }
}
