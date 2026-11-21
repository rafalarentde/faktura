<?php
declare(strict_types=1);

use App\Core\App;
use App\Http\AdminController;
use App\Http\AuthController;
use App\Http\CustomerController;
use App\Http\DashboardController;
use App\Http\DunningController;
use App\Http\ExportController;
use App\Http\InvoiceController;
use App\Http\ItemController;
use App\Http\MailController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireCompany;
use App\Http\OfferController;
use App\Http\PdfController;
use App\Http\SettingsController;
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
    $r->addRoute('GET', '/admin/audit', $admin('auditLog'));
    $r->addRoute('GET', '/admin/companies', $admin('index'));
    $r->addRoute('GET', '/admin/companies/new', $admin('newForm'));
    $r->addRoute('POST', '/admin/companies', $admin('create'));
    $r->addRoute('GET', '/admin/companies/{id:\d+}', $admin('show'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/access', $admin('access'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/users', $admin('addUser'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/users/{uid:\d+}/remove', $admin('removeUser'));
    $r->addRoute('POST', '/admin/companies/{id:\d+}/users/{uid:\d+}/reset-2fa', $admin('reset2fa'));

    // Customers (no delete route in v1).
    $customer = fn (string $method) => $app->protect([CustomerController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/customers', $customer('index'));
    $r->addRoute('GET', '/customers/{id:\d+}', $customer('show'));
    $r->addRoute('POST', '/customers', $customer('create'));
    $r->addRoute('POST', '/customers/{id:\d+}', $customer('update'));
    $r->addRoute('POST', '/customers/{id:\d+}/vies', $customer('vies'));

    // Items and services (no delete route in v1; items are deactivated instead).
    $item = fn (string $method) => $app->protect([ItemController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/items', $item('index'));
    $r->addRoute('GET', '/items/{id:\d+}', $item('show'));
    $r->addRoute('POST', '/items', $item('create'));
    $r->addRoute('POST', '/items/{id:\d+}', $item('update'));
    $r->addRoute('POST', '/items/{id:\d+}/active', $item('active'));

    // Invoices and credit notes (Task 16). Credit notes use the same routes; offers get their own
    // routes (Task 19). /invoices/recalc returns JSON live totals (CSRF via X-CSRF-Token header).
    $invoice = fn (string $method) => $app->protect([InvoiceController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/invoices', $invoice('index'));
    $r->addRoute('GET', '/invoices/new', $invoice('newForm'));
    $r->addRoute('POST', '/invoices', $invoice('create'));
    $r->addRoute('POST', '/invoices/recalc', $invoice('recalc'));
    $r->addRoute('GET', '/invoices/{id:\d+}', $invoice('show'));
    $r->addRoute('POST', '/invoices/{id:\d+}/save', $invoice('save'));
    $r->addRoute('POST', '/invoices/{id:\d+}/issue', $invoice('issue'));
    $r->addRoute('POST', '/invoices/{id:\d+}/delete', $invoice('delete'));
    $r->addRoute('POST', '/invoices/{id:\d+}/payments', $invoice('payments'));
    $r->addRoute('POST', '/invoices/{id:\d+}/correct', $invoice('correct'));

    // Offers (Task 19): the same editor as invoices (shared AbstractDocumentController), plus the
    // decision status and the conversion of an accepted offer into a draft invoice.
    $offer = fn (string $method) => $app->protect([OfferController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/offers', $offer('index'));
    $r->addRoute('GET', '/offers/new', $offer('newForm'));
    $r->addRoute('POST', '/offers', $offer('create'));
    $r->addRoute('POST', '/offers/recalc', $offer('recalc'));
    $r->addRoute('GET', '/offers/{id:\d+}', $offer('show'));
    $r->addRoute('POST', '/offers/{id:\d+}/save', $offer('save'));
    $r->addRoute('POST', '/offers/{id:\d+}/issue', $offer('issue'));
    $r->addRoute('POST', '/offers/{id:\d+}/delete', $offer('delete'));
    $r->addRoute('POST', '/offers/{id:\d+}/status', $offer('status'));
    $r->addRoute('POST', '/offers/{id:\d+}/convert', $offer('convert'));

    // Dunning letters (Task 21): overdue list + issued letters, the on-the-fly preview (GET form,
    // nothing stored), creation (= issue with a number; nothing is sent) and the issued letter.
    $dunning = fn (string $method) => $app->protect([DunningController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/dunning', $dunning('index'));
    $r->addRoute('GET', '/dunning/new', $dunning('preview'));
    $r->addRoute('POST', '/dunning', $dunning('store'));
    $r->addRoute('GET', '/dunning/{id:\d+}', $dunning('show'));

    // PDF of an issued invoice, credit note, offer or dunning letter (Tasks 17, 19, 21); ?download=1 for an attachment.
    $r->addRoute('GET', '/documents/{id:\d+}/pdf', $app->protect([PdfController::class, 'show'], [RequireCompany::class]));

    // Send an issued invoice, credit note, offer or dunning letter by email (Tasks 18, 19, 21). GET shows the editable preview
    // without side effects; only the POST (an explicit click, CSRF-checked) sends.
    $mail = fn (string $method) => $app->protect([MailController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/documents/{id:\d+}/send', $mail('form'));
    $r->addRoute('POST', '/documents/{id:\d+}/send', $mail('send'));

    // Settings: company/dunning/mail/logo are owner-only (checked in the controller, 403 for
    // role 'user'); the security tab is personal. The logo is served only for the context company.
    $settings = fn (string $method) => $app->protect([SettingsController::class, $method], [RequireCompany::class]);
    $r->addRoute('GET', '/settings', $settings('index'));
    $r->addRoute('GET', '/settings/company', $settings('company'));
    $r->addRoute('POST', '/settings/company', $settings('saveCompany'));
    $r->addRoute('POST', '/settings/company/logo', $settings('uploadLogo'));
    $r->addRoute('POST', '/settings/company/logo/remove', $settings('removeLogo'));
    $r->addRoute('GET', '/company/logo', $settings('logo'));
    $r->addRoute('GET', '/settings/numbers', $settings('numbers'));
    $r->addRoute('POST', '/settings/numbers', $settings('saveNumbers'));
    $r->addRoute('GET', '/settings/dunning', $settings('dunning'));
    $r->addRoute('POST', '/settings/dunning', $settings('saveDunning'));
    $r->addRoute('GET', '/settings/mail', $settings('mail'));
    $r->addRoute('POST', '/settings/mail', $settings('saveMail'));
    $r->addRoute('POST', '/settings/mail/test', $settings('mailTest'));
    $r->addRoute('GET', '/settings/security', $settings('security'));
    $r->addRoute('POST', '/settings/security/backup-codes', $settings('regenerateCodes'));
    $r->addRoute('POST', '/settings/security/password', $settings('changePassword'));

    // Company data export as a ZIP (Task 23): owner-only, also for a suspended company (reading and exporting stay possible).
    $r->addRoute('GET', '/settings/export', $app->protect([ExportController::class, 'index'], [RequireCompany::class]));
    $r->addRoute('POST', '/settings/export', $app->protect([ExportController::class, 'create'], [RequireCompany::class]));
};
