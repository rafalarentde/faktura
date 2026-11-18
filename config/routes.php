<?php
declare(strict_types=1);

use App\Core\App;
use App\Http\AuthController;
use App\Http\HomeController;
use FastRoute\RouteCollector;

/*
 * Every route target must come from $app->protect() (full login + 2FA required) or
 * $app->open() (public). POST requests are CSRF-checked centrally by App.
 * Later tasks append their routes below.
 */
return function (App $app, RouteCollector $r): void {
    $r->addRoute('GET', '/login', $app->open([AuthController::class, 'showLogin']));
    $r->addRoute('POST', '/login', $app->open([AuthController::class, 'login']));
    $r->addRoute('GET', '/login/2fa', $app->open([AuthController::class, 'showSecondFactor']));
    $r->addRoute('POST', '/login/2fa', $app->open([AuthController::class, 'verifySecondFactor']));
    $r->addRoute('POST', '/logout', $app->open([AuthController::class, 'logout']));
    $r->addRoute('GET', '/2fa/setup', $app->protect([AuthController::class, 'showSetup']));
    $r->addRoute('POST', '/2fa/setup', $app->protect([AuthController::class, 'confirmSetup']));

    $r->addRoute('GET', '/', $app->protect([HomeController::class, 'index']));
};
