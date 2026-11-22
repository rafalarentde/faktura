<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A route target built by App::protect() / App::open(): the handler wrapped in its
 * middleware. Handlers are [ControllerClass::class, 'method'] (controller constructed
 * with the App) or a Closure, both called as (Request $request, array $routeParams): Response.
 * Middleware are class names (constructed with the App) or objects, invoked as
 * (Request $request, callable $next): Response.
 */
final class Route
{
    /**
     * @param array{0: class-string, 1: string}|\Closure $handler
     * @param list<class-string|object> $middleware outermost first
     */
    public function __construct(
        private App $app,
        private array|\Closure $handler,
        private array $middleware,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(Request $request, array $params): Response
    {
        $next = function (Request $req) use ($params): Response {
            if ($this->handler instanceof \Closure) {
                return ($this->handler)($req, $params);
            }
            [$class, $method] = $this->handler;
            return (new $class($this->app))->$method($req, $params);
        };
        foreach (array_reverse($this->middleware) as $mw) {
            $instance = is_string($mw) ? new $mw($this->app) : $mw;
            $inner = $next;
            $next = static fn (Request $req): Response => $instance($req, $inner);
        }
        return $next($request);
    }
}
