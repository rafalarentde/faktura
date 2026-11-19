<?php
declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\BackupCodes;
use App\Auth\RateLimiter;
use App\Auth\Totp;
use App\Auth\UserService;
use App\Http\Middleware\RequireLogin;
use App\I18n\Translator;
use App\Tenant\AccessDenied;
use App\Tenant\CompanyContext;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * HTTP kernel and service container. handle() never echoes; public/index.php sends the
 * Response. Services are created lazily, once per App; the session, CSRF guard, current
 * user and language are per request.
 */
final class App
{
    private string $root;
    private SessionStore $sessionStore;
    private \Closure $clock;

    /** @var list<\Closure> */
    private array $routeDefiners = [];
    private ?Dispatcher $dispatcher = null;

    private ?Crypto $crypto = null;
    private ?Translator $translator = null;
    private ?Environment $twig = null;
    private ?RateLimiter $rateLimiter = null;
    private ?Totp $totp = null;
    private ?BackupCodes $backupCodes = null;
    private ?AuthService $auth = null;
    private ?UserService $users = null;

    // Per request.
    private ?Request $request = null;
    private ?Session $session = null;
    private ?Csrf $csrf = null;
    /** @var array<string, mixed>|false|null null = not loaded yet, false = nobody */
    private array|false|null $currentUser = null;
    private CompanyContext|false|null $context = null;
    private string $lang = Translator::FALLBACK;

    /**
     * @param array<string, mixed> $config contents of config/config.php
     * @param SessionStore|null $sessionStore defaults to native PHP sessions; tests pass ArraySessionStore
     * @param \Closure|null $clock returns unix time; tests pass a fake
     */
    public function __construct(
        private array $config,
        private Db $db,
        ?SessionStore $sessionStore = null,
        ?\Closure $clock = null,
    ) {
        $this->root = dirname(__DIR__, 2);
        $this->sessionStore = $sessionStore ?? new NativeSessionStore();
        $this->clock = $clock ?? time(...);
        $this->routeDefiners[] = \Closure::fromCallable(require $this->root . '/config/routes.php');
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->session = null;
        $this->csrf = null;
        $this->currentUser = null;
        $this->context = null;
        $this->lang = Translator::FALLBACK;
        try {
            $this->session()->start();
            $this->lang = $this->detectLang($request);
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->serverError($e);
        }
        return $this->withDefaultHeaders($response);
    }

    /** Adds route definitions (same signature as config/routes.php); call before the first handle(). */
    public function routes(\Closure $definer): void
    {
        if ($this->dispatcher !== null) {
            throw new \LogicException('Routes are already compiled.');
        }
        $this->routeDefiners[] = $definer;
    }

    /**
     * Route target that requires a full login with 2FA set up (RequireLogin), followed by
     * any extra middleware.
     *
     * @param array{0: class-string, 1: string}|\Closure $handler
     * @param list<class-string|object> $middleware
     */
    public function protect(array|\Closure $handler, array $middleware = []): Route
    {
        return new Route($this, $handler, [RequireLogin::class, ...$middleware]);
    }

    /**
     * Public route target (no login required). CSRF is still enforced for POST.
     *
     * @param array{0: class-string, 1: string}|\Closure $handler
     * @param list<class-string|object> $middleware
     */
    public function open(array|\Closure $handler, array $middleware = []): Route
    {
        return new Route($this, $handler, $middleware);
    }

    public function db(): Db
    {
        return $this->db;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function request(): Request
    {
        return $this->request ?? throw new \LogicException('No current request.');
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto((string) $this->config('app_key', ''));
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator($this->root . '/lang');
    }

    /** Current UI language (de|pl|en). */
    public function lang(): string
    {
        return $this->lang;
    }

    /** @param array<string, scalar> $vars */
    public function t(string $key, array $vars = []): string
    {
        return $this->translator()->t($key, $this->lang, $vars);
    }

    public function session(): Session
    {
        return $this->session ??= new Session($this->sessionStore, $this->request?->isHttps() ?? false, $this->clock);
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf($this->session());
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter($this->db, 5, 900, $this->clock);
    }

    public function totp(): Totp
    {
        return $this->totp ??= new Totp();
    }

    public function backupCodes(): BackupCodes
    {
        return $this->backupCodes ??= new BackupCodes($this->db);
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->db, $this->rateLimiter(), $this->totp(), $this->backupCodes(), $this->crypto(), $this->clock
        );
    }

    public function users(): UserService
    {
        return $this->users ??= new UserService($this->db);
    }

    /**
     * Fully logged-in user's row (id, email, totp_enabled, ui_lang, is_admin) or null.
     * Null at the password_ok stage: a non-null result always means password AND second
     * factor passed (or, with totp_enabled = 0, a session that RequireLogin pins to /2fa/setup).
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        if ($this->currentUser === null) {
            $id = $this->session()->loginStage() === 'full' ? $this->session()->userId() : null;
            $row = $id === null ? null : $this->db->one(
                'SELECT id, email, totp_enabled, ui_lang, is_admin FROM users WHERE id = ?',
                [$id]
            );
            $this->currentUser = $row ?? false;
        }
        return $this->currentUser === false ? null : $this->currentUser;
    }

    /**
     * Active company of the request (cached per request like currentUser()), or null.
     * Only for a fully logged-in user. The session's company id is used when the user is
     * still a member of it; otherwise a user with exactly one company gets it selected (and
     * stored); a user with several and no valid selection, or none, gets null. Company ids
     * from the request are never consulted.
     */
    public function context(): ?CompanyContext
    {
        if ($this->context === null) {
            $this->context = $this->resolveContext() ?? false;
        }
        return $this->context === false ? null : $this->context;
    }

    /** Forgets the cached context (tests; flows that change the selected company should redirect instead). */
    public function resetContext(): void
    {
        $this->context = null;
    }

    private function resolveContext(): ?CompanyContext
    {
        $user = $this->currentUser();
        if ($user === null) {
            return null;
        }
        $userId = (int) $user['id'];
        $session = $this->session();
        $selected = $session->companyId();
        if ($selected !== null) {
            try {
                return CompanyContext::forUser($this->db, $userId, $selected);
            } catch (AccessDenied) {
                $session->clearCompanyId(); // membership gone: do not trust the stored id
            }
        }
        $rows = $this->db->all(
            'SELECT cu.company_id FROM company_users cu JOIN companies c ON c.id = cu.company_id WHERE cu.user_id = ? LIMIT 2',
            [$userId]
        );
        if (count($rows) !== 1) {
            return null;
        }
        try {
            $ctx = CompanyContext::forUser($this->db, $userId, (int) $rows[0]['company_id']);
        } catch (AccessDenied) {
            return null;
        }
        $session->setCompanyId($ctx->id());
        return $ctx;
    }

    public function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }
        $twig = new Environment(new FilesystemLoader($this->root . '/templates'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $this->config('twig_cache', false),
        ]);
        $t = fn (string $key, array $vars = []): string => $this->t($key, $vars);
        $twig->addFunction(new TwigFunction('t', $t));
        $twig->addFilter(new TwigFilter('t', $t));
        $twig->addFunction(new TwigFunction('csrf_token', fn (): string => $this->csrf()->token()));
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            fn (): string => '<input type="hidden" name="' . Csrf::FIELD . '" value="' . $this->csrf()->token() . '">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('asset', fn (string $path): string => $this->asset($path)));
        $twig->addFunction(new TwigFunction('current_lang', fn (): string => $this->lang));
        return $this->twig = $twig;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], int $status = 200): Response
    {
        return Response::html($this->twig()->render($template, $vars), $status);
    }

    private function dispatch(Request $request): Response
    {
        $info = $this->dispatcher()->dispatch($request->method, $request->path);
        if ($info[0] === Dispatcher::NOT_FOUND) {
            return $this->errorPage(404, 'error.not_found');
        }
        if ($info[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return $this->errorPage(405, 'error.method_not_allowed')->withHeader('Allow', implode(', ', $info[1]));
        }
        [, $route, $params] = $info;
        if (!$route instanceof Route) {
            throw new \LogicException(
                "Route {$request->method} {$request->path} must be declared with \$app->protect() or \$app->open()."
            );
        }
        // CSRF is enforced centrally for every state-changing request, whatever the route.
        if (!$request->isSafeMethod() && !$this->csrf()->check($request->input(Csrf::FIELD))) {
            return $this->errorPage(419, 'error.csrf');
        }
        return $route($request, $params);
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= \FastRoute\simpleDispatcher(function (RouteCollector $r): void {
            foreach ($this->routeDefiners as $define) {
                $define($this, $r);
            }
        });
    }

    private function errorPage(int $status, string $messageKey, ?string $detail = null): Response
    {
        return $this->render('error.twig', [
            'status' => $status,
            'message' => $this->t($messageKey),
            'detail' => $detail,
        ], $status);
    }

    private function serverError(\Throwable $e): Response
    {
        error_log('faktura: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $detail = $this->config('debug', false) === true ? (string) $e : null;
        try {
            return $this->errorPage(500, 'error.server', $detail);
        } catch (\Throwable) {
            $body = '<!doctype html><meta charset="utf-8"><title>500</title><h1>500</h1>';
            if ($detail !== null) {
                $body .= '<pre>' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }
            return Response::html($body, 500);
        }
    }

    private function withDefaultHeaders(Response $r): Response
    {
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        return $r;
    }

    private function detectLang(Request $request): string
    {
        $fromSession = $this->session()->get('lang');
        if (is_string($fromSession) && in_array($fromSession, Translator::SUPPORTED, true)) {
            return $fromSession;
        }
        $candidates = [];
        foreach (explode(',', (string) $request->header('Accept-Language')) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(substr(trim($bits[0]), 0, 2));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q=([0-9.]+)\s*$/', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0 && in_array($tag, Translator::SUPPORTED, true)) {
                $candidates[] = [$q, -$i, $tag];
            }
        }
        if ($candidates === []) {
            return Translator::FALLBACK;
        }
        rsort($candidates);
        return $candidates[0][2];
    }

    private function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = $this->root . '/public' . $path;
        return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
    }
}
