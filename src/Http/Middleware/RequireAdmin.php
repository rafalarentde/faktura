<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/** Runs after RequireLogin: only users.is_admin = 1 pass, everyone else gets a generic 403. */
final class RequireAdmin
{
    public function __construct(private App $app)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $user = $this->app->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        if ((int) ($user['is_admin'] ?? 0) !== 1) {
            return $this->app->render('error.twig', [
                'status' => 403,
                'message' => $this->app->t('error.forbidden'),
                'detail' => null,
            ], 403);
        }
        return $next($request);
    }
}
