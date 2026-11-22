<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\UserService;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Personal security settings (password, backup codes, 2FA status). Only a login is needed, no company:
 * an admin without a company must be able to change the password and renew the backup codes. When a
 * company is active the page shows the settings tabs like the other settings pages.
 *
 * Every credential change bumps users.session_epoch (all other sessions of the user end) and re-stores
 * the new value in this session, so the acting session stays logged in.
 */
final class SecurityController
{
    /** Failed password checks (backup codes and password change share it) per user and window. */
    private const PASSWORD_LIMIT_KEY = 'security:user:';

    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function security(Request $request, array $params): Response
    {
        return $this->page(passwordChanged: $request->queryParam('pw') === '1');
    }

    /** @param array<string, string> $params */
    public function regenerateCodes(Request $request, array $params): Response
    {
        $userId = $this->userId();
        $problem = $this->checkCurrentPassword($request->input('current_password'), $userId);
        if ($problem !== null) {
            return $this->page(codesErrors: ['current_password' => $problem[0]], status: $problem[1]);
        }
        $epoch = $this->app->db()->transaction(function () use ($userId, &$codes): int {
            $codes = $this->app->backupCodes()->generate($userId);
            $epoch = $this->app->users()->bumpSessionEpoch($userId);
            $this->audit('security.backup_codes_regenerate');
            return $epoch;
        });
        $this->app->session()->setEpoch($epoch);
        // Rendered directly like the 2FA setup page: the plain codes are shown exactly once.
        return $this->page(newCodes: $codes)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function changePassword(Request $request, array $params): Response
    {
        $userId = $this->userId();
        $current = $request->input('current_password');
        $problem = $this->checkCurrentPassword($current, $userId);
        if ($problem !== null) {
            return $this->page(passwordErrors: ['current_password' => $problem[0]], status: $problem[1]);
        }
        $new = $request->input('new_password');
        $errors = [];
        if (mb_strlen($new, 'UTF-8') < UserService::MIN_PASSWORD_LENGTH || strlen($new) > 1024) {
            $errors['new_password'] = 'security.err_password_short';
        } elseif (hash_equals($current, $new)) {
            $errors['new_password'] = 'security.err_password_same';
        }
        if (!hash_equals($new, $request->input('new_password_confirm'))) {
            $errors['new_password_confirm'] = 'security.err_password_mismatch';
        }
        if ($errors !== []) {
            return $this->page(passwordErrors: $errors, status: 422);
        }
        $epoch = $this->app->db()->transaction(function () use ($userId, $new): int {
            $this->app->db()->exec(
                'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
                [password_hash($new, PASSWORD_ARGON2ID), $userId]
            );
            $epoch = $this->app->users()->bumpSessionEpoch($userId);
            $this->audit('security.password_change');
            return $epoch;
        });
        $this->app->rateLimiter()->clear(self::PASSWORD_LIMIT_KEY . $userId);
        // Stay logged in (new epoch), but a stolen old session id or form token is worthless from now on.
        $this->app->session()->setEpoch($epoch);
        $this->app->session()->regenerate();
        $this->app->csrf()->rotate();
        return Response::redirect('/settings/security?pw=1');
    }

    private function userId(): int
    {
        return (int) ($this->app->currentUser()['id'] ?? throw new \LogicException('RequireLogin must run first.'));
    }

    /**
     * Current-password check with a per-user failure limit (5 per 15 minutes), hit first: the attempt is
     * recorded before the password is verified, so concurrent guesses cannot all pass.
     *
     * @return array{0: string, 1: int}|null [translation key, HTTP status] or null when correct
     */
    private function checkCurrentPassword(string $password, int $userId): ?array
    {
        $limiter = $this->app->rateLimiter();
        $key = self::PASSWORD_LIMIT_KEY . $userId;
        if (!$limiter->attempt($key)) {
            return ['security.err_locked', 429];
        }
        $hash = (string) ($this->app->db()->one('SELECT password_hash FROM users WHERE id = ?', [$userId])['password_hash'] ?? '');
        if ($password === '' || strlen($password) > 1024 || !password_verify($password, $hash)) {
            return ['security.err_current_password', 422];
        }
        $limiter->clear($key);
        return null;
    }

    /** The active company is recorded when there is one (a null company id is resolved by Audit). */
    private function audit(string $action): void
    {
        $this->app->audit()->log($action, [], null, $this->userId());
    }

    /**
     * @param list<string>|null $newCodes plain backup codes, shown once
     * @param array<string, string> $codesErrors
     * @param array<string, string> $passwordErrors
     */
    private function page(?array $newCodes = null, array $codesErrors = [], array $passwordErrors = [], int $status = 200, bool $passwordChanged = false): Response
    {
        $user = $this->app->currentUser();
        $context = $this->app->context();
        $tabs = [];
        if ($context !== null) {
            $isOwner = $context->role() === 'owner';
            /** @var list<array{key: string, label: string, path: string, owner_only: bool}> $all */
            $all = require dirname(__DIR__, 2) . '/config/settings_tabs.php';
            $tabs = array_values(array_filter($all, fn (array $t): bool => $isOwner || !$t['owner_only']));
        }
        $translate = fn (array $errors): array => array_map(fn (string $key): string => $this->app->t($key), $errors);
        return $this->app->render('settings/security.twig', [
            'tabs' => $tabs,
            'active_tab' => 'security',
            'company_name' => $context === null ? null : (string) $context->company()['name'],
            'totp_active' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'must_change' => (int) ($user['must_change_password'] ?? 0) === 1,
            'new_codes' => $newCodes,
            'codes_errors' => $translate($codesErrors),
            'password_errors' => $translate($passwordErrors),
            'password_changed' => $passwordChanged,
        ], $status);
    }
}
