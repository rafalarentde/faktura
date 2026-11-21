<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Core\App;
use App\Core\Response;
use FastRoute\RouteCollector;
use Tests\Http\HttpTestCase;

/**
 * Deployment security review (Task 24): response headers, files that must never be served
 * from the web root, and production error pages that never leak internals.
 */
final class SecurityHeadersTest extends HttpTestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        \App\Core\ErrorHandling::uninstall();
    }

    public function test_security_headers_present(): void
    {
        foreach (['/login', '/does-not-exist'] as $path) {
            $h = $this->get($path)->headers;
            $this->assertSame('nosniff', $h['X-Content-Type-Options'] ?? null, $path);
            $this->assertSame('same-origin', $h['Referrer-Policy'] ?? null, $path);
            $this->assertSame('DENY', $h['X-Frame-Options'] ?? null, $path);
            $this->assertNotEmpty($h['Permissions-Policy'] ?? '', $path);
            $this->assertArrayNotHasKey('Strict-Transport-Security', $h, 'HSTS only when the app is configured for https');
        }
    }

    public function test_csp_has_no_unsafe_inline_or_eval_and_locks_down_framing_and_forms(): void
    {
        $csp = $this->get('/login')->headers['Content-Security-Policy'] ?? '';
        $this->assertNotSame('', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $bits = preg_split('/\s+/', trim($part)) ?: [];
            $directives[array_shift($bits)] = $bits;
        }
        $this->assertSame(["'self'"], $directives['script-src'] ?? null);
        $this->assertSame(["'self'"], $directives['style-src'] ?? null);
        $this->assertSame(["'none'"], $directives['frame-ancestors'] ?? null);
        $this->assertSame(["'self'"], $directives['form-action'] ?? null);
        $this->assertSame(["'self'"], $directives['base-uri'] ?? null);
        $this->assertSame(["'self'"], $directives['default-src'] ?? null);
    }

    public function test_hsts_is_sent_when_base_url_is_https(): void
    {
        $this->app = $this->makeApp(['base_url' => 'https://faktury.panbehapowiec.pl']);
        $hsts = $this->get('/login')->headers['Strict-Transport-Security'] ?? '';
        $this->assertMatchesRegularExpression('/^max-age=\d{7,}\b/', $hsts);
        $this->app = $this->makeApp(['base_url' => 'http://localhost:8000']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $this->get('/login')->headers);
    }

    public function test_dotfiles_and_config_not_served(): void
    {
        $path = self::ROOT . '/public/.htaccess';
        $this->assertFileExists($path);
        $ht = (string) file_get_contents($path);
        // Everything that is not an existing static file goes to the front controller.
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $ht);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $ht);
        // Dotfiles (.git, .env, .htpasswd, ...) are refused, also in sub-directories.
        $this->assertMatchesRegularExpression('#RewriteRule \(\^\|/\)\\\\\. - \[F,L\]#', $ht);
        $this->assertStringContainsString('FilesMatch "^\.', $ht);
        // Config/data/source extensions are refused should anyone drop them into public/.
        foreach (['sqlite', 'sql', 'ini', 'log', 'lock', 'json', 'env', 'bak'] as $ext) {
            $this->assertMatchesRegularExpression('/FilesMatch "[^"]*\b' . $ext . '\b[^"]*"/i', $ht, $ext);
        }
        $this->assertStringContainsString('Options -Indexes', $ht);
        $this->assertStringContainsString('Header unset X-Powered-By', $ht);
    }

    public function test_web_root_holds_only_the_front_controller_and_assets(): void
    {
        $public = realpath(self::ROOT . '/public');
        $this->assertNotFalse($public);
        $php = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr((string) $file, strlen($public) + 1);
            if (preg_match('/\.(php|phtml|sqlite|sql|ini|env|log|lock|bak)$/i', $rel) === 1) {
                $php[] = $rel;
            }
        }
        $this->assertSame(['index.php'], $php);
        // Nothing sensitive lives inside the web root; config, storage and vendor sit above it.
        foreach (['config', 'storage', 'vendor', 'src', 'migrations', 'templates', 'bin'] as $dir) {
            $this->assertDirectoryDoesNotExist($public . '/' . $dir);
        }
    }

    public function test_real_config_and_storage_contents_are_git_ignored(): void
    {
        $ignore = (string) file_get_contents(self::ROOT . '/.gitignore');
        foreach (['/config/config.php', '/vendor/', '/storage/pdf-cache/*', '/storage/tmp/*', '/storage/logos/*'] as $rule) {
            $this->assertStringContainsString($rule, $ignore);
        }
    }

    public function test_errors_do_not_leak_stack_traces_in_production_mode(): void
    {
        \App\Core\ErrorHandling::install(false); // what public/index.php does: warnings become exceptions
        $this->app = $this->makeApp(['debug' => false]);
        $this->app->routes(function (App $app, RouteCollector $r): void {
            $r->addRoute('GET', '/_t/boom', $app->open(function (): Response {
                throw new \RuntimeException('secret-detail-xyz at /srv/secret/path');
            }));
            $r->addRoute('GET', '/_t/warn', $app->open(function (): Response {
                $a = [];
                return Response::html((string) $a['missing']);
            }));
        });
        foreach (['/_t/boom', '/_t/warn'] as $path) {
            $r = $this->get($path);
            $this->assertSame(500, $r->status, $path);
            $this->assertStringNotContainsString('secret-detail-xyz', $r->body);
            $this->assertStringNotContainsString('Stack trace', $r->body);
            $this->assertStringNotContainsString('#0 ', $r->body);
            $this->assertStringNotContainsString('.php', $r->body);
            $this->assertStringNotContainsString('RuntimeException', $r->body);
            $this->assertStringNotContainsString('Undefined array key', $r->body);
            $this->assertSame('nosniff', $r->headers['X-Content-Type-Options'] ?? null);
        }
    }

    public function test_front_controller_bootstrap_failure_is_generic_and_leaks_no_paths(): void
    {
        $env = ['FAKTURA_CONFIG' => '/nonexistent/dir/config-secret-name.php', 'PATH' => (string) getenv('PATH')];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', self::ROOT . '/public/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->assertStringContainsString('Service unavailable', $out);
        $this->assertStringNotContainsString('config-secret-name', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
        $this->assertStringNotContainsString('Warning', $out);
    }
}
