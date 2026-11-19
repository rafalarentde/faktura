<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/**
 * Runs after RequireLogin. Lets the request through only when an active company is resolved:
 * no company at all gives a 403 page, several without a selection redirect to the chooser.
 */
final class RequireCompany
{
    public const CHOOSE_PATH = '/company/choose';

    public function __construct(private App $app)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->app->context() !== null) {
            return $next($request);
        }
        $user = $this->app->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $count = $this->app->db()->one('SELECT COUNT(*) AS c FROM company_users cu JOIN companies c ON c.id = cu.company_id WHERE cu.user_id = ?', [(int) $user['id']]);
        if ((int) ($count['c'] ?? 0) === 0) {
            return $this->app->render('no_company.twig', [], 403);
        }
        return Response::redirect(self::CHOOSE_PATH);
    }
}
