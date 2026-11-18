<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

/** One-time recovery codes; only Argon2id hashes are stored. */
final class BackupCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private Db $db)
    {
    }

    /**
     * Replaces the user's whole set of codes; returns the plain codes (shown once).
     *
     * @return list<string>
     */
    public function generate(int $userId, int $count = 8): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Count must be positive.');
        }
        $codes = [];
        while (count($codes) < $count) {
            $codes[$this->randomCode()] = true;
        }
        $codes = array_keys($codes);

        $hashes = array_map(
            fn (string $c): string => password_hash($this->normalise($c), PASSWORD_ARGON2ID),
            $codes
        );
        $this->db->transaction(function (Db $db) use ($userId, $hashes): void {
            $db->exec('DELETE FROM backup_codes WHERE user_id = ?', [$userId]);
            foreach ($hashes as $h) {
                $db->exec('INSERT INTO backup_codes (user_id, code_hash) VALUES (?, ?)', [$userId, $h]);
            }
        });
        return $codes;
    }

    public function consume(int $userId, string $code): bool
    {
        $norm = $this->normalise($code);
        if (preg_match('/\A[' . self::ALPHABET . ']{8}\z/', $norm) !== 1) {
            return false;
        }
        return (bool) $this->db->transaction(function (Db $db) use ($userId, $norm): bool {
            $rows = $db->all(
                'SELECT id, code_hash FROM backup_codes WHERE user_id = ? AND used_at IS NULL' . $db->forUpdate(),
                [$userId]
            );
            foreach ($rows as $row) {
                if (password_verify($norm, (string) $row['code_hash'])) {
                    // The used_at IS NULL guard makes a concurrent double-spend impossible.
                    return $db->exec(
                        'UPDATE backup_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
                        [date('Y-m-d H:i:s'), $row['id']]
                    ) === 1;
                }
            }
            return false;
        });
    }

    private function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }
        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }

    private function normalise(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', $code));
    }
}
