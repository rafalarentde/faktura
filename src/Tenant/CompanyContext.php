<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Core\Db;

/**
 * The company a user is currently working in. The only way to obtain one is forUser(), which
 * verifies membership in company_users, so holding a CompanyContext proves access.
 */
final class CompanyContext
{
    /** @param array<string, mixed> $company the companies row */
    private function __construct(
        private int $userId,
        private array $company,
        private string $role,
    ) {
    }

    /** @throws AccessDenied when the user is not a member, or the company does not exist */
    public static function forUser(Db $db, int $userId, int $companyId): self
    {
        $membership = $db->one(
            'SELECT role FROM company_users WHERE company_id = ? AND user_id = ?',
            [$companyId, $userId]
        );
        $company = $membership === null ? null : $db->one('SELECT * FROM companies WHERE id = ?', [$companyId]);
        if ($membership === null || $company === null) {
            throw new AccessDenied('No access to this company.');
        }
        return new self($userId, $company, (string) $membership['role']);
    }

    public function id(): int
    {
        return (int) $this->company['id'];
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /** 'owner' or 'user' */
    public function role(): string
    {
        return $this->role;
    }

    /** @return array<string, mixed> the companies row, loaded once */
    public function company(): array
    {
        return $this->company;
    }

    /**
     * May this company issue documents at $now? Active: yes. Trial: until the end of the
     * access_until day, in Europe/Berlin time. Suspended, expired trial or an unknown status: no.
     */
    public function canIssue(\DateTimeImmutable $now): bool
    {
        $status = $this->company['access_status'] ?? null;
        if ($status === 'active') {
            return true;
        }
        if ($status === 'trial') {
            $until = $this->company['access_until'] ?? null;
            return is_string($until) && $until !== '' && substr($until, 0, 10) >= $now->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        }
        return false;
    }
}
