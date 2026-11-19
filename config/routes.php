<?php
declare(strict_types=1);

use App\Core\App;
use App\Http\AdminController;
use App\Http\AuthController;
use App\Http\DashboardController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireCompany;
use App\Http\SwitchController;
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

    $r->addRoute('GET', '/', $app->protect([DashboardController::class, 'index'], [RequireCompany::class]));

    // Shell switchers. /lang is open so the login page can switch language too. The company
    // chooser must not use RequireCompany (RequireCompany redirects there).
    $r->addRoute('POST', '/lang', $app->open([SwitchController::class, 'language']));
    $r->addRoute('GET', RequireCompany::CHOOSE_PATH, $app->protect([SwitchController::class, 'choose']));
    $r->addRoute('POST', '/company/switch', $app->protect([SwitchController::class, 'switch']));

    // Administration: every route is admin-only; admins need no company.
    $admin = fn (string $method) => $app->protect([AdminController::class, $method], [RequireAdmin::class]);
    $r->addRoute('GET', '/admin/companies', $admin('index'));
    $r->addRoute('GET', '/admin/companies/new', $admin('newForm'));
    $r->addRoute('POST', '/admin/companies', $admin('create'));
    $r->addRoute('GET', '/admin/companies/{id:\d+}', $admin('show'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/access', $admin('access'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/users', $admin('addUser'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/users/{uid:\d+}/remove', $admin('removeUser'));
};
