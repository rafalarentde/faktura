<?php
declare(strict_types=1);

namespace App\Auth;

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

    private \Closure $clock;

    public function __construct(
        private Db $db,
        private RateLimiter $limiter,
        private Totp $totp,
        private BackupCodes $backupCodes,
        private Crypto $crypto,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function attemptPassword(string $email, string $password, string $ip): AuthResult
    {
        $email = UserService::normaliseEmail($email);
        $keys = $this->loginKeys($email, $ip);
        foreach ($keys as $key) {
            if ($this->limiter->tooMany($key)) {
                return AuthResult::locked();
            }
        }

        $user = strlen($email) <= 190
            ? $this->db->one('SELECT id, password_hash, totp_enabled FROM users WHERE email = ?', [$email])
            : null;
        $tooLong = strlen($password) > self::MAX_PASSWORD_BYTES;
        $hash = $user !== null ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $ok = password_verify($tooLong ? '' : $password, $hash) && $user !== null && !$tooLong;

        if (!$ok) {
            foreach ($keys as $key) {
                $this->limiter->hit($key);
            }
            return AuthResult::invalid();
        }
        $id = (int) $user['id'];
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $this->db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $id]);
        }
        return AuthResult::ok($id, (int) $user['totp_enabled'] === 1);
    }

    /** Second step: a 6-digit TOTP code (replay-protected) or a backup code. */
    public function verifySecondFactor(int $userId, string $code, string $ip): AuthResult
    {
        $key = $this->secondFactorKey($userId);
        if ($this->limiter->tooMany($key)) {
            return AuthResult::locked();
        }
        $user = $this->db->one('SELECT id, email, totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$userId]);
        $code = trim($code);
        $ok = false;
        if ($user !== null && (int) $user['totp_enabled'] === 1) {
            $ok = preg_match('/\A[0-9]{3} ?[0-9]{3}\z/', $code) === 1
                ? $this->acceptTotp($userId, $this->secret($user), $code)
                : $this->backupCodes->consume($userId, $code);
        }
        if (!$ok) {
            $this->limiter->hit($key);
            return AuthResult::invalid();
        }
        $this->limiter->clear($key);
        foreach ($this->loginKeys((string) $user['email'], $ip) as $k) {
            $this->limiter->clear($k);
        }
        return AuthResult::ok($userId);
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
        if ($this->limiter->tooMany($key)) {
            return AuthResult::locked();
        }
        $user = $this->db->one('SELECT totp_secret_enc, totp_enabled FROM users WHERE id = ?', [$userId]);
        $ok = $user !== null
            && (int) $user['totp_enabled'] === 0
            && !empty($user['totp_secret_enc'])
            && $this->acceptTotp($userId, $this->secret($user), trim($code));
        if (!$ok) {
            $this->limiter->hit($key);
            return AuthResult::invalid();
        }
        $codes = $this->db->transaction(function (Db $db) use ($userId): array {
            $db->exec('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$userId]);
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

    private function secondFactorKey(int $userId): string
    {
        return '2fa:user:' . $userId;
    }
}
