<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class RequestResponseTest extends TestCase
{
    public function test_request_accessors(): void
    {
        $r = new Request('post', '/x', ['q' => '1'], ['a' => 'b', 'arr' => ['c']], ['k' => 'v'], [
            'HTTP_X_CUSTOM_HEADER' => 'yes', 'REMOTE_ADDR' => '198.51.100.1', 'CONTENT_TYPE' => 'text/plain',
        ]);
        $this->assertSame('POST', $r->method);
        $this->assertSame('/x', $r->path);
        $this->assertSame('b', $r->input('a'));
        $this->assertSame('', $r->input('arr'));
        $this->assertSame('', $r->input('missing'));
        $this->assertSame('yes', $r->header('X-Custom-Header'));
        $this->assertSame('text/plain', $r->header('content-type'));
        $this->assertNull($r->header('X-None'));
        $this->assertSame('198.51.100.1', $r->ip());
        $this->assertFalse($r->isHttps());
    }

    public function test_is_https(): void
    {
        $this->assertTrue((new Request('GET', '/', server: ['HTTPS' => 'on']))->isHttps());
        $this->assertFalse((new Request('GET', '/', server: ['HTTPS' => 'off']))->isHttps());
        $this->assertTrue((new Request('GET', '/', server: ['SERVER_PORT' => '443']))->isHttps());
        $this->assertTrue((new Request('GET', '/', server: ['HTTP_X_FORWARDED_PROTO' => 'https']))->isHttps());
    }

    public function test_from_globals_parses_path(): void
    {
        $r = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/a%20b/c?x=1'], ['x' => '1'], [], []);
        $this->assertSame('/a b/c', $r->path);
        $this->assertSame(['x' => '1'], $r->query);
        $this->assertSame('/', Request::fromGlobals(['REQUEST_URI' => '//'], [], [], [])->path);
    }

    public function test_response_factories(): void
    {
        $r = Response::redirect('/login');
        $this->assertSame(302, $r->status);
        $this->assertSame('/login', $r->headers['Location']);

        $h = Response::html('<p>x</p>', 201);
        $this->assertSame(201, $h->status);
        $this->assertSame('text/html; charset=utf-8', $h->headers['Content-Type']);

        $j = Response::json(['a' => 'ü'], 400);
        $this->assertSame(400, $j->status);
        $this->assertSame('application/json', $j->headers['Content-Type']);
        $this->assertSame(['a' => 'ü'], json_decode($j->body, true));
    }

    public function test_redirect_rejects_header_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect("/x\r\nSet-Cookie: a=b");
    }
}
