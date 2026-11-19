<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ErrorHandling;
use PHPUnit\Framework\TestCase;

final class ErrorHandlingTest extends TestCase
{
    private string|false $displayErrors;
    private int $errorReporting;

    protected function setUp(): void
    {
        $this->displayErrors = ini_get('display_errors');
        $this->errorReporting = error_reporting();
    }

    protected function tearDown(): void
    {
        ErrorHandling::uninstall();
        ini_set('display_errors', (string) $this->displayErrors);
        error_reporting($this->errorReporting);
    }

    public function test_warning_becomes_error_exception(): void
    {
        ErrorHandling::install(false);
        try {
            $a = [];
            $x = $a['missing'];
            $this->fail('ErrorException expected');
        } catch (\ErrorException $e) {
            $this->assertSame(E_WARNING, $e->getSeverity());
            $this->assertStringContainsString('missing', $e->getMessage());
        }
    }

    public function test_suppressed_warning_is_not_converted(): void
    {
        ErrorHandling::install(false);
        $v = @file_get_contents('/nonexistent/' . bin2hex(random_bytes(4)));
        $this->assertFalse($v);
    }

    public function test_display_errors_follows_debug(): void
    {
        ErrorHandling::install(false);
        $this->assertSame('0', ini_get('display_errors'));
        $this->assertSame(E_ALL, error_reporting());
        ErrorHandling::uninstall();
        ErrorHandling::install(true);
        $this->assertSame('1', ini_get('display_errors'));
    }

    public function test_uninstall_restores_previous_handler(): void
    {
        $marker = static fn (): bool => true;
        set_error_handler($marker);
        try {
            ErrorHandling::install(false);
            ErrorHandling::uninstall();
            $current = set_error_handler(null);
            $this->assertSame($marker, $current);
        } finally {
            restore_error_handler(); // pops the null we pushed
            restore_error_handler(); // pops $marker
        }
    }
}
