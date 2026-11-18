<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Db;

/** Creates user accounts (CLI bin/create-user.php, later the admin panel). */
final class UserService
{
    public const MIN_PASSWORD_LENGTH = 12;

    public function __construct(private Db $db)
    {
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** @throws \InvalidArgumentException for an invalid/duplicate email or a weak password */
    public function create(string $email, string $password, bool $isAdmin): int
    {
        $email = self::normaliseEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('Password must have at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if ($this->db->one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw new \InvalidArgumentException('A user with this email already exists.');
        }
        $this->db->exec(
            'INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)',
            [$email, password_hash($password, PASSWORD_ARGON2ID), $isAdmin ? 1 : 0]
        );
        return $this->db->lastId();
    }
}
