<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

/** Sliding-window attempt counter; keys are stored as sha256 hashes. */
final class RateLimiter
{
    private \Closure $clock;

    public function __construct(
        private Db $db,
        private int $max = 5,
        private int $windowSec = 900,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function tooMany(string $key): bool
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE `key` = ? AND at > ?',
            [$this->hash($key), $this->cutoff()]
        );
        return (int) ($row['n'] ?? 0) >= $this->max;
    }

    public function hit(string $key): void
    {
        $h = $this->hash($key);
        $this->db->exec('DELETE FROM login_attempts WHERE `key` = ? AND at <= ?', [$h, $this->cutoff()]);
        $this->db->exec('INSERT INTO login_attempts (`key`, at) VALUES (?, ?)', [$h, ($this->clock)()]);
    }

    public function clear(string $key): void
    {
        $this->db->exec('DELETE FROM login_attempts WHERE `key` = ?', [$this->hash($key)]);
    }

    private function cutoff(): int
    {
        return ($this->clock)() - $this->windowSec;
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
