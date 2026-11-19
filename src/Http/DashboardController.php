<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/** Start page (GET /, behind RequireCompany). An empty state for now; Task 22 adds the data cards. */
final class DashboardController
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->app->render('dashboard.twig');
    }
}
