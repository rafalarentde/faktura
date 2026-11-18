<?php
declare(strict_types=1);

namespace App\Core;

/** Storage behind Session: native PHP sessions in production, an array in tests. */
interface SessionStore
{
    /**
     * Starts (or resumes) the session; idempotent.
     *
     * @param array{lifetime:int, path:string, domain:string, secure:bool, httponly:bool, samesite:string} $cookieParams
     */
    public function start(string $name, array $cookieParams, int $maxLifetime): void;

    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Removes all data but keeps the session. */
    public function clear(): void;

    /** New session id, data kept, old session deleted (session_regenerate_id(true)). */
    public function regenerate(): void;

    /** Deletes the session and its data. */
    public function destroy(): void;
}
