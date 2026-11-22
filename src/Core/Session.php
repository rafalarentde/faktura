<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Login session with staged authentication: none -> password_ok -> full.
 * Expires after IDLE_TIMEOUT without requests or ABSOLUTE_LIFETIME after login.
 */
final class Session
{
    public const COOKIE_NAME = 'faktura_sid';
    public const IDLE_TIMEOUT = 7200;
    public const ABSOLUTE_LIFETIME = 43200;
    /** Seconds the password-only stage may wait for the second factor; then back to /login. */
    public const PASSWORD_STAGE_TTL = 600;

    private const K_USER = '_uid';
    private const K_STAGE = '_stage';
    private const K_CREATED = '_created';
    private const K_LAST = '_last';
    private const K_COMPANY = '_company';
    private const K_EPOCH = '_epoch';
    private const K_PW_AT = '_pw_at';

    private \Closure $clock;
    private bool $started = false;

    public function __construct(private SessionStore $store, private bool $secure, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** @return array{lifetime:int, path:string, domain:string, secure:bool, httponly:bool, samesite:string} */
    public function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->store->start(self::COOKIE_NAME, $this->cookieParams(), self::ABSOLUTE_LIFETIME);

        $now = ($this->clock)();
        $last = $this->store->get(self::K_LAST);
        $created = $this->store->get(self::K_CREATED);
        if (is_int($last) && is_int($created)
            && ($now - $last > self::IDLE_TIMEOUT || $now - $created > self::ABSOLUTE_LIFETIME)
        ) {
            $this->store->clear();
            $this->store->regenerate();
        }
        // The half-logged-in state must not linger: the password stage is only valid for a short time.
        $pwAt = $this->store->get(self::K_PW_AT);
        if ($this->store->get(self::K_STAGE) === 'password_ok' && is_int($pwAt) && $now - $pwAt > self::PASSWORD_STAGE_TTL) {
            $this->store->clear();
            $this->store->regenerate();
        }
        if (!is_int($this->store->get(self::K_CREATED))) {
            $this->store->set(self::K_CREATED, $now);
        }
        $this->store->set(self::K_LAST, $now);
    }

    /** Releases the session lock; see SessionStore::close(). Call only when nothing else will be written. */
    public function close(): void
    {
        $this->store->close();
    }

    public function id(): string
    {
        return $this->store->id();
    }

    public function userId(): ?int
    {
        $id = $this->store->get(self::K_USER);
        return is_int($id) ? $id : null;
    }

    /** @return 'none'|'password_ok'|'full' */
    public function loginStage(): string
    {
        $stage = $this->store->get(self::K_STAGE);
        if ($this->userId() === null || !in_array($stage, ['password_ok', 'full'], true)) {
            return 'none';
        }
        return $stage;
    }

    /** First factor passed: new session id, absolute lifetime starts now. */
    public function passwordOk(int $userId): void
    {
        $this->store->regenerate();
        $this->store->set(self::K_USER, $userId);
        $this->store->set(self::K_STAGE, 'password_ok');
        $this->store->set(self::K_PW_AT, ($this->clock)());
        $this->store->remove(self::K_COMPANY);
        $this->store->set(self::K_CREATED, ($this->clock)());
    }

    /**
     * Second factor passed (or account still has to set it up): new session id.
     *
     * @param int $epoch the user's users.session_epoch at this moment; App::currentUser() compares it on every request
     */
    public function fullLogin(int $epoch = 0): void
    {
        if ($this->loginStage() !== 'password_ok') {
            throw new \LogicException('Full login requires the password stage first.');
        }
        $this->store->regenerate();
        $this->store->set(self::K_STAGE, 'full');
        $this->store->remove(self::K_PW_AT);
        $this->store->set(self::K_EPOCH, $epoch);
        $this->store->remove(self::K_COMPANY);
    }

    /** The users.session_epoch stored at login, or null (session from before the epoch existed). */
    public function epoch(): ?int
    {
        $e = $this->store->get(self::K_EPOCH);
        return is_int($e) ? $e : null;
    }

    /** Re-stores the epoch after this session itself changed a credential, so it stays logged in. */
    public function setEpoch(int $epoch): void
    {
        $this->store->set(self::K_EPOCH, $epoch);
    }

    /** Company selected for this login (only a candidate: App::context() re-checks membership). */
    public function companyId(): ?int
    {
        $id = $this->store->get(self::K_COMPANY);
        return is_int($id) ? $id : null;
    }

    public function setCompanyId(int $companyId): void
    {
        $this->store->set(self::K_COMPANY, $companyId);
    }

    public function clearCompanyId(): void
    {
        $this->store->remove(self::K_COMPANY);
    }

    public function logout(): void
    {
        $this->store->destroy();
    }

    /** New session id with the data kept (after a credential change); the old id stops working. */
    public function regenerate(): void
    {
        $this->store->regenerate();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->store->remove($key);
    }
}
