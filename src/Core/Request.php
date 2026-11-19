<?php
declare(strict_types=1);

namespace App\Core;

/** Immutable HTTP request: method, decoded path, query/post/cookie arrays and $_SERVER-style data. */
final class Request
{
    public readonly string $method;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files $_FILES-shaped uploads (field => name/type/tmp_name/size/error)
     */
    public function __construct(
        string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
        public readonly array $files = [],
    ) {
        $this->method = strtoupper($method);
    }

    /**
     * @param array<string, mixed>|null $server
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $files
     */
    public static function fromGlobals(?array $server = null, ?array $query = null, ?array $post = null, ?array $cookies = null, ?array $files = null): self
    {
        $server ??= $_SERVER;
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        $path = '/' . ltrim($path, '/');
        return new self(
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $query ?? $_GET,
            $post ?? $_POST,
            $cookies ?? $_COOKIE,
            $server,
            $files ?? $_FILES,
        );
    }

    /** POST field as string; '' when missing or not a string (e.g. an array injected via name[]). */
    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** Query parameter as string; '' when missing or not a string. */
    public function queryParam(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $v = $this->server['HTTP_' . $key]
            ?? (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? ($this->server[$key] ?? null) : null);
        return is_string($v) ? $v : null;
    }

    /** Client address from the socket only; X-Forwarded-For is not trusted. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    /**
     * X-Forwarded-Proto is honoured because it can only ever make the cookie stricter
     * (adds the Secure flag); it never relaxes any check.
     */
    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off')
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** GET and HEAD (and OPTIONS) do not change state and need no CSRF token. */
    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
