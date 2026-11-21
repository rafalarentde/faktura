<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthResult;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/** Login (password, then TOTP/backup code), first-time 2FA setup and logout. */
final class AuthController
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function showLogin(Request $request, array $params): Response
    {
        return $this->redirectIfStarted() ?? $this->loginPage();
    }

    /** @param array<string, string> $params */
    public function login(Request $request, array $params): Response
    {
        if ($redirect = $this->redirectIfStarted()) {
            return $redirect;
        }
        $email = $request->input('email');
        $result = $this->app->auth()->attemptPassword($email, $request->input('password'), $request->ip());
        if (!$result->isOk()) {
            return $this->loginPage($email, $result);
        }
        $session = $this->app->session();
        $session->passwordOk((int) $result->userId);
        $this->app->csrf()->rotate();
        $lang = $this->app->db()->one('SELECT ui_lang FROM users WHERE id = ?', [$result->userId])['ui_lang'] ?? null;
        if (is_string($lang)) {
            $session->set('lang', $lang);
        }
        if (!$result->totpEnabled) {
            // Nothing to verify yet: RequireLogin keeps the user on /2fa/setup until it is done.
            $session->fullLogin();
            return Response::redirect('/2fa/setup');
        }
        return Response::redirect('/login/2fa');
    }

    /** @param array<string, string> $params */
    public function showSecondFactor(Request $request, array $params): Response
    {
        return $this->requirePasswordStage() ?? $this->app->render('login_2fa.twig', ['error' => null]);
    }

    /** @param array<string, string> $params */
    public function verifySecondFactor(Request $request, array $params): Response
    {
        if ($redirect = $this->requirePasswordStage()) {
            return $redirect;
        }
        $session = $this->app->session();
        $result = $this->app->auth()->verifySecondFactor((int) $session->userId(), $request->input('code'), $request->ip());
        if (!$result->isOk()) {
            return $this->app->render('login_2fa.twig', ['error' => $this->errorKey($result, 'login.error_code')], $this->status($result));
        }
        $session->fullLogin();
        $this->app->csrf()->rotate();
        return Response::redirect('/');
    }

    /** @param array<string, string> $params */
    public function showSetup(Request $request, array $params): Response
    {
        return $this->setupDone() ?? $this->setupPage();
    }

    /** @param array<string, string> $params */
    public function confirmSetup(Request $request, array $params): Response
    {
        if ($redirect = $this->setupDone()) {
            return $redirect;
        }
        $user = $this->app->currentUser();
        $result = $this->app->auth()->confirmTotpSetup((int) $user['id'], $request->input('code'));
        if (!$result->isOk()) {
            return $this->setupPage($this->errorKey($result, 'login.error_code'), $this->status($result));
        }
        // Rendered directly (not stored anywhere): the plain backup codes are shown exactly once.
        return $this->app->render('2fa_setup.twig', [
            'backup_codes' => $result->backupCodes,
            'qr' => null,
            'secret' => null,
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $userId = $this->app->session()->userId();
        $this->app->session()->logout();
        if ($userId !== null) {
            $this->app->audit()->logSilently('auth.logout', [], \App\Core\Audit::NO_COMPANY, $userId);
        }
        return Response::redirect('/login');
    }

    private function loginPage(string $email = '', ?AuthResult $result = null): Response
    {
        return $this->app->render('login.twig', [
            'email' => $email,
            'error' => $result === null ? null : $this->errorKey($result, 'login.error_invalid'),
        ], $result === null ? 200 : $this->status($result));
    }

    private function setupPage(?string $error = null, int $status = 200): Response
    {
        $user = $this->app->currentUser();
        $secret = $this->app->auth()->pendingTotpSecret((int) $user['id']);
        $totp = $this->app->totp();
        return $this->app->render('2fa_setup.twig', [
            'qr' => $totp->qrDataUri($totp->provisioningUri($secret, (string) $user['email'])),
            'secret' => $secret,
            'backup_codes' => null,
            'error' => $error,
        ], $status);
    }

    /** Already in the login process or logged in: continue there instead of a fresh login. */
    private function redirectIfStarted(): ?Response
    {
        return match ($this->app->session()->loginStage()) {
            'full' => Response::redirect('/'),
            'password_ok' => Response::redirect('/login/2fa'),
            default => null,
        };
    }

    private function requirePasswordStage(): ?Response
    {
        return match ($this->app->session()->loginStage()) {
            'none' => Response::redirect('/login'),
            'full' => Response::redirect('/'),
            default => null,
        };
    }

    private function setupDone(): ?Response
    {
        return (int) ($this->app->currentUser()['totp_enabled'] ?? 0) === 1 ? Response::redirect('/') : null;
    }

    private function errorKey(AuthResult $result, string $invalidKey): string
    {
        return $result->status === AuthResult::LOCKED ? 'login.error_locked' : $invalidKey;
    }

    private function status(AuthResult $result): int
    {
        return $result->status === AuthResult::LOCKED ? 429 : 422;
    }
}
