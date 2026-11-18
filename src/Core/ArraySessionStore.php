<?php
declare(strict_types=1);

namespace App\Core;

/**
 * In-memory SessionStore for tests. One instance plays the role of the browser's session
 * cookie across several App::handle() calls; it records what a real session would do.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $data = [];
    private string $id;
    private int $regenerations = 0;
    private bool $destroyed = false;
    private ?string $name = null;

    /** @var array<string, mixed> */
    private array $cookieParams = [];

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function start(string $name, array $cookieParams, int $maxLifetime): void
    {
        $this->name = $name;
        $this->cookieParams = $cookieParams;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->id = bin2hex(random_bytes(16));
        $this->destroyed = true;
    }

    public function regenerations(): int
    {
        return $this->regenerations;
    }

    public function wasDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /** @return array<string, mixed> cookie parameters passed by the last start() */
    public function cookieParams(): array
    {
        return $this->cookieParams;
    }
}
