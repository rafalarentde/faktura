<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use FastRoute\RouteCollector;

final class KernelTest extends HttpTestCase
{
    private function withBoomRoute(App $app): App
    {
        $app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/boom', $app->open(function (): Response {
                throw new \RuntimeException('secret-detail-xyz');
            }));
            $r->addRoute('GET', '/raw', fn (): Response => Response::html('unprotected'));
            $r->addRoute('GET', '/hello/{name}', $app->open(
                fn (Request $req, array $p): Response => Response::html('hi ' . $p['name'])
            ));
        });
        return $app;
    }

    public function test_unknown_route_is_404(): void
    {
        $r = $this->get('/no-such-page');
        $this->assertSame(404, $r->status);
        $this->assertStringContainsString($this->app->t('error.not_found'), $r->body);
    }

    public function test_wrong_method_is_405_with_allow_header(): void
    {
        $r = $this->get('/logout');
        $this->assertSame(405, $r->status);
        $this->assertSame('POST', $r->headers['Allow'] ?? null);
    }

    public function test_route_params_are_passed_to_handler(): void
    {
        $this->app = $this->withBoomRoute($this->makeApp());
        $this->assertSame('hi anna', $this->get('/hello/anna')->body);
    }

    public function test_exception_gives_500_without_details(): void
    {
        $this->app = $this->withBoomRoute($this->makeApp());
        $r = $this->get('/boom');
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('secret-detail-xyz', $r->body);
        $this->assertStringContainsString($this->app->t('error.server'), $r->body);
    }

    public function test_debug_shows_exception_details(): void
    {
        $this->app = $this->withBoomRoute($this->makeApp(['debug' => true]));
        $r = $this->get('/boom');
        $this->assertSame(500, $r->status);
        $this->assertStringContainsString('secret-detail-xyz', $r->body);
    }

    public function test_route_not_declared_via_protect_or_open_is_refused(): void
    {
        $this->app = $this->withBoomRoute($this->makeApp());
        $r = $this->get('/raw');
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('unprotected', $r->body);
    }

    public function test_security_headers(): void
    {
        $r = $this->get('/login');
        $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame('same-origin', $r->headers['Referrer-Policy']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function test_language_from_accept_language(): void
    {
        $r = $this->get('/login', ['HTTP_ACCEPT_LANGUAGE' => 'pl-PL,pl;q=0.9,en;q=0.8']);
        $this->assertStringContainsString('lang="pl"', $r->body);
        $this->assertStringContainsString($this->app->translator()->t('login.title', 'pl'), $r->body);
        $r = $this->get('/login', ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        $this->assertStringContainsString('lang="de"', $r->body);
    }

    public function test_context_is_null_for_now(): void
    {
        $this->assertNull($this->app->context());
    }
}
