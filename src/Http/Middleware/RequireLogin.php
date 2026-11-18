<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Lets only fully logged-in users through. Half-logged-in users go to /login/2fa; accounts
 * without enabled 2FA are sent to /2fa/setup from every page, without exceptions.
 */
final class RequireLogin
{
    public const SETUP_PATH = '/2fa/setup';

    public function __construct(private App $app)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $session = $this->app->session();
        $stage = $session->loginStage();
        if ($stage === 'none') {
            return Response::redirect('/login');
        }
        if ($stage === 'password_ok') {
            return Response::redirect('/login/2fa');
        }
        $user = $this->app->currentUser();
        if ($user === null) {
            $session->logout(); // account deleted meanwhile
            return Response::redirect('/login');
        }
        if ((int) $user['totp_enabled'] !== 1 && $request->path !== self::SETUP_PATH) {
            return Response::redirect(self::SETUP_PATH);
        }
        return $next($request);
    }
}
