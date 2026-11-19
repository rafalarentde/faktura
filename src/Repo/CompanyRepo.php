<?php
declare(strict_types=1);

namespace App\Repo;

use App\Auth\UserService;
use App\Core\Db;

/**
 * Company administration. NOT tenant-scoped: it reads and writes any company, so it must only
 * be used behind the RequireAdmin middleware (admin screens). Business data never goes through it.
 *
 * Validation failures throw \InvalidArgumentException whose message is a translation key
 * (`admin.err_*`) the controller can show; nothing is written in that case.
 */
final class CompanyRepo
{
    public const STATUSES = ['active', 'suspended', 'trial'];
    public const ROLES = ['owner', 'user'];

    public function __construct(private Db $db, private UserService $users)
    {
    }

    /**
     * @param array<string, mixed> $data name (required), plan (default 'free'),
     *        access_status (default 'suspended': access is granted after payment), access_until
     * @return int the new company id
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
            throw new \InvalidArgumentException('admin.err_name');
        }
        $plan = trim((string) ($data['plan'] ?? '')) ?: 'free';
        if (preg_match('/\A[a-z0-9_-]{1,32}\z/', $plan) !== 1) {
            throw new \InvalidArgumentException('admin.err_plan');
        }
        $status = (string) (($data['access_status'] ?? '') ?: 'suspended');
        $until = $this->until($status, isset($data['access_until']) ? (string) $data['access_until'] : null);
        $this->db->exec(
            'INSERT INTO companies (name, plan, access_status, access_until) VALUES (?, ?, ?, ?)',
            [$name, $plan, $status, $until]
        );
        return $this->db->lastId();
    }

    /** @return list<array<string, mixed>> all companies with `user_count`, by name */
    public function all(): array
    {
        return $this->db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id = c.id) AS user_count
             FROM companies c ORDER BY c.name, c.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    /** @param ?string $until Y-m-d or null; required for 'trial' */
    public function setAccess(int $id, string $status, ?string $until): void
    {
        $until = $this->until($status, $until);
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('admin.err_company');
        }
        $this->db->exec('UPDATE companies SET access_status = ?, access_until = ? WHERE id = ?', [$status, $until, $id]);
    }

    /** @return list<array<string, mixed>> user_id, email, role */
    public function members(int $companyId): array
    {
        return $this->db->all(
            'SELECT u.id AS user_id, u.email, cu.role FROM company_users cu
             JOIN users u ON u.id = cu.user_id WHERE cu.company_id = ? ORDER BY u.email',
            [$companyId]
        );
    }

    /**
     * Links an existing user (by lowercased email) to the company. When no such user exists and
     * an initial password is given, a non-admin user is created first.
     *
     * @return int the user id
     */
    public function addUser(int $companyId, string $email, string $role, ?string $initialPassword = null): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('admin.err_role');
        }
        if ($this->find($companyId) === null) {
            throw new \InvalidArgumentException('admin.err_company');
        }
        $email = UserService::normaliseEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new \InvalidArgumentException('admin.err_email');
        }
        $initialPassword = ($initialPassword === null || $initialPassword === '') ? null : $initialPassword;
        return $this->db->transaction(function () use ($companyId, $email, $role, $initialPassword): int {
            $user = $this->db->one('SELECT id FROM users WHERE email = ?', [$email]);
            if ($user === null) {
                if ($initialPassword === null) {
                    throw new \InvalidArgumentException('admin.err_unknown_user');
                }
                if (mb_strlen($initialPassword, 'UTF-8') < UserService::MIN_PASSWORD_LENGTH) {
                    throw new \InvalidArgumentException('admin.err_password_short');
                }
                $userId = $this->users->create($email, $initialPassword, false);
            } else {
                $userId = (int) $user['id'];
            }
            if ($this->db->one('SELECT 1 AS x FROM company_users WHERE company_id = ? AND user_id = ?', [$companyId, $userId]) !== null) {
                throw new \InvalidArgumentException('admin.err_duplicate_member');
            }
            $this->db->exec('INSERT INTO company_users (company_id, user_id, role) VALUES (?, ?, ?)', [$companyId, $userId, $role]);
            return $userId;
        });
    }

    public function removeUser(int $companyId, int $userId): void
    {
        $this->db->exec('DELETE FROM company_users WHERE company_id = ? AND user_id = ?', [$companyId, $userId]);
    }

    /** Validates status and until together; returns the normalised until (Y-m-d or null). */
    private function until(string $status, ?string $until): ?string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('admin.err_status');
        }
        $until = $until === null ? null : trim($until);
        if ($until === '') {
            $until = null;
        }
        if ($until !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $d->format('Y-m-d') !== $until) {
                throw new \InvalidArgumentException('admin.err_until');
            }
        }
        if ($status === 'trial' && $until === null) {
            throw new \InvalidArgumentException('admin.err_trial_until');
        }
        return $until;
    }
}
