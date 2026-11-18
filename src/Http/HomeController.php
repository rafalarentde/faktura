<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/** Placeholder start page until the dashboard exists. */
final class HomeController
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->app->render('home.twig', ['user' => $this->app->currentUser()]);
    }
}
