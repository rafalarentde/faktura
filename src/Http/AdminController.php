<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CompanyRepo;

/** Admin screens for companies, their users and access status. Routes are gated by RequireAdmin. */
final class AdminController
{
    private const AUDIT_MAX = 200;
    private const AUDIT_PER_PAGE = 50;

    private CompanyRepo $companies;

    public function __construct(private App $app)
    {
        $this->companies = new CompanyRepo($app->db(), $app->users());
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->list();
    }

    /**
     * Read-only audit log viewer: the latest 200 events, 50 per page, newest first, with the
     * optional GET filters `company` (id) and `action` (prefix of the action name). Like the rest of
     * this controller it deliberately reads across companies (the admin is the one non-tenant-scoped
     * reader): audit_log is joined read-only to companies and users for the display names. Filter input
     * never reaches SQL as anything but a `?` parameter: the company id must be plain digits, the action
     * prefix keeps only [a-z0-9_.] and its `_` is escaped for LIKE.
     *
     * @param array<string, string> $params
     */
    public function auditLog(Request $request, array $params): Response
    {
        $companyRaw = $request->queryParam('company');
        $company = preg_match('/\A[1-9][0-9]{0,17}\z/', $companyRaw) === 1 ? (int) $companyRaw : null;
        $prefix = substr((string) preg_replace('/[^a-z0-9_.]/', '', strtolower($request->queryParam('action'))), 0, 64);

        $where = [];
        $args = [];
        if ($company !== null) {
            $where[] = 'a.company_id = ?';
            $args[] = $company;
        }
        if ($prefix !== '') {
            $where[] = "a.action LIKE ? ESCAPE '!'";
            $args[] = str_replace('_', '!_', $prefix) . '%';
        }
        $cond = $where === [] ? '1=1' : implode(' AND ', $where);
        $db = $this->app->db();
        $total = min(self::AUDIT_MAX, (int) ($db->one("SELECT COUNT(*) AS c FROM audit_log a WHERE $cond", $args)['c'] ?? 0));
        $pages = max(1, (int) ceil($total / self::AUDIT_PER_PAGE));
        $pageRaw = $request->queryParam('page');
        $page = min($pages, ctype_digit($pageRaw) && strlen($pageRaw) < 6 ? max(1, (int) $pageRaw) : 1);
        $rows = $db->all(
            'SELECT a.id, a.at, a.action, a.detail, a.company_id, c.name AS company_name, u.email AS user_email '
            . 'FROM audit_log a LEFT JOIN companies c ON c.id = a.company_id LEFT JOIN users u ON u.id = a.user_id '
            . "WHERE $cond ORDER BY a.id DESC LIMIT " . self::AUDIT_PER_PAGE . ' OFFSET ' . (($page - 1) * self::AUDIT_PER_PAGE),
            $args
        );
        $berlin = new \DateTimeZone('Europe/Berlin');
        $entries = array_map(fn (array $r): array => [
            'time' => (new \DateTimeImmutable((string) $r['at'], new \DateTimeZone('UTC')))->setTimezone($berlin)->format('Y-m-d H:i:s'),
            'company_id' => $r['company_id'] === null ? null : (int) $r['company_id'],
            'company' => $r['company_name'],
            'user' => $r['user_email'],
            'action' => (string) $r['action'],
            'detail' => self::detailPairs($r['detail']),
        ], array_slice($rows, 0, self::AUDIT_PER_PAGE));

        $filters = ['company' => $company === null ? '' : (string) $company, 'action' => $prefix];
        $link = fn (int $p): string => '/admin/audit?' . http_build_query(array_filter($filters, fn (string $v): bool => $v !== '') + ['page' => $p]);
        return $this->app->render('admin/audit.twig', [
            'entries' => $entries,
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'prev' => $page > 1 ? $link($page - 1) : null,
            'next' => $page < $pages ? $link($page + 1) : null,
        ]);
    }

    /**
     * A stored detail as "key=value" strings (lists joined by commas, booleans as true/false); an
     * undecodable value is shown as a cut-off raw string. Escaping is left to Twig.
     *
     * @return list<string>
     */
    private static function detailPairs(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [mb_substr($json, 0, 200)];
        }
        $out = [];
        foreach ($data as $key => $value) {
            $text = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_array($value) => implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '?', $value)),
                default => (string) $value,
            };
            $out[] = $key . '=' . mb_substr($text, 0, 120);
        }
        return $out;
    }

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        return $this->newPage(['name' => '', 'plan' => 'free', 'access_status' => 'suspended', 'access_until' => ''], null);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $form = [
            'name' => trim((string) $request->input('name')),
            'plan' => trim((string) $request->input('plan')),
            'access_status' => (string) $request->input('access_status'),
            'access_until' => trim((string) $request->input('access_until')),
        ];
        try {
            // State change and audit row commit together or not at all.
            $id = $this->app->db()->transaction(function () use ($form): int {
                $id = $this->companies->create($form);
                $company = $this->companies->find($id);
                $this->audit($id, 'admin.company_create', [
                    'company_id' => $id,
                    'name' => $company['name'],
                    'plan' => $company['plan'],
                    'status' => $company['access_status'],
                    'until' => $this->date($company['access_until']),
                ]);
                return $id;
            });
        } catch (\InvalidArgumentException $e) {
            return $this->newPage($form, $this->message($e), 422);
        }
        return Response::redirect('/admin/companies/' . $id);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $company = $this->companies->find((int) $params['id']);
        if ($company === null) {
            return $this->notFound();
        }
        return $this->detail($company, null);
    }

    /** @param array<string, string> $params */
    public function access(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $company = $this->companies->find($id);
        if ($company === null) {
            return $this->notFound();
        }
        $action = (string) $request->input('action');
        if ($action === 'activate') {
            $status = 'active';
            $until = trim((string) $request->input('until'));
            if ($until === '') {
                $until = $this->today()->modify('+1 year')->format('Y-m-d');
            }
        } elseif ($action === 'suspend') {
            $status = 'suspended';
            $until = null;
        } else {
            return $this->list($this->app->t('admin.err_status'), 422);
        }
        try {
            $this->app->db()->transaction(function () use ($id, $status, $until, $company): void {
                $this->companies->setAccess($id, $status, $until);
                $this->audit($id, 'admin.access_change', [
                    'company_id' => $id,
                    'old_status' => $company['access_status'],
                    'new_status' => $status,
                    'old_until' => $this->date($company['access_until']),
                    'new_until' => $until,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->list($this->message($e), 422);
        }
        return Response::redirect('/admin/companies');
    }

    /** @param array<string, string> $params */
    public function addUser(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $company = $this->companies->find($id);
        if ($company === null) {
            return $this->notFound();
        }
        $email = (string) $request->input('email');
        $role = (string) $request->input('role');
        try {
            $this->app->db()->transaction(function () use ($id, $email, $role, $request): void {
                $userId = $this->companies->addUser($id, $email, $role, (string) $request->input('password'));
                // Never log the initial password.
                $this->audit($id, 'admin.user_add', [
                    'company_id' => $id,
                    'user_id' => $userId,
                    'email' => \App\Auth\UserService::normaliseEmail($email),
                    'role' => $role,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->detail($company, $this->message($e), 422);
        }
        return Response::redirect('/admin/companies/' . $id);
    }

    /** @param array<string, string> $params */
    public function removeUser(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $uid = (int) $params['uid'];
        if ($this->companies->find($id) === null) {
            return $this->notFound();
        }
        if (!$this->companies->isMember($id, $uid)) {
            return $this->notFound();
        }
        try {
            $this->app->db()->transaction(function () use ($id, $uid): void {
                $this->companies->removeUser($id, $uid);
                $this->audit($id, 'admin.user_remove', ['company_id' => $id, 'user_id' => $uid]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->detail((array) $this->companies->find($id), $this->message($e), 422);
        }
        return Response::redirect('/admin/companies/' . $id);
    }

    /** Only for a member of this company (404 otherwise); ends in the setup flow at the user's next login. @param array<string, string> $params */
    public function reset2fa(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $uid = (int) $params['uid'];
        if ($this->companies->find($id) === null || !$this->companies->isMember($id, $uid)) {
            return $this->notFound();
        }
        // The reset and its audit row commit together or not at all. No secrets in the detail.
        $this->app->db()->transaction(function () use ($id, $uid): void {
            $this->companies->resetTwoFactor($uid);
            $this->app->users()->bumpSessionEpoch($uid); // the user's sessions end (the admin's own is unaffected)
            $this->audit($id, 'admin.user_reset_2fa', ['company_id' => $id, 'user_id' => $uid]);
        });
        return Response::redirect('/admin/companies/' . $id);
    }

    /**
     * "Set temporary password" (there is no self-service reset: this is the way back in). Only for a member of
     * this company (404 otherwise) and never for an administrator account (422: admins change their own
     * password in /settings/security). Generates a random password, forces a change at the next page view
     * (users.must_change_password), ends every session of the user (session_epoch) and lifts a login lockout.
     * The password is rendered once in the response (no-store), stored only as a hash and never logged.
     *
     * @param array<string, string> $params
     */
    public function tempPassword(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $uid = (int) $params['uid'];
        $company = $this->companies->find($id);
        if ($company === null || !$this->companies->isMember($id, $uid)) {
            return $this->notFound();
        }
        $user = $this->app->db()->one('SELECT email, is_admin FROM users WHERE id = ?', [$uid]);
        if ($user === null) {
            return $this->notFound();
        }
        if ((int) $user['is_admin'] === 1) {
            return $this->detail($company, $this->app->t('admin.err_temp_admin'), 422);
        }
        $password = self::randomPassword();
        $this->app->db()->transaction(function () use ($id, $uid, $password): void {
            $this->app->db()->exec(
                'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?',
                [password_hash($password, PASSWORD_ARGON2ID), $uid]
            );
            $this->app->users()->bumpSessionEpoch($uid);
            $this->audit($id, 'admin.user_temp_password', ['company_id' => $id, 'user_id' => $uid]);
        });
        $this->app->auth()->clearLoginLock((string) $user['email']);
        return $this->detail($company, null, 200, ['email' => (string) $user['email'], 'password' => $password])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** 16 characters (about 80 bits) without look-alikes, in groups of four. */
    private static function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chars = '';
            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[random_int(0, $max)];
            }
            $groups[] = $chars;
        }
        return implode('-', $groups);
    }

    private function list(?string $error = null, int $status = 200): Response
    {
        return $this->app->render('admin/companies.twig', [
            'companies' => $this->companies->all(),
            'error' => $error,
            'default_until' => $this->today()->modify('+1 year')->format('Y-m-d'),
        ], $status);
    }

    /** @param array<string, string> $form */
    private function newPage(array $form, ?string $error, int $status = 200): Response
    {
        return $this->app->render('admin/company_new.twig', ['form' => $form, 'error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $company
     * @param array{email: string, password: string}|null $temp a temporary password to show once
     */
    private function detail(array $company, ?string $error, int $status = 200, ?array $temp = null): Response
    {
        return $this->app->render('admin/company.twig', [
            'company' => $company,
            'members' => $this->companies->members((int) $company['id']),
            'error' => $error,
            'temp' => $temp,
        ], $status);
    }

    private function notFound(): Response
    {
        return $this->app->render('error.twig', [
            'status' => 404,
            'message' => $this->app->t('error.not_found'),
            'detail' => null,
        ], 404);
    }

    private function message(\InvalidArgumentException $e): string
    {
        return str_starts_with($e->getMessage(), 'admin.err_')
            ? $this->app->t($e->getMessage())
            : $this->app->t('admin.err_generic');
    }

    private function today(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone(new \DateTimeZone('Europe/Berlin'))->setTime(0, 0);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * Audit row for a change to $companyId made by the signed-in admin (user id from the session).
     *
     * @param array<string, mixed> $detail must never contain secrets
     */
    private function audit(int $companyId, string $action, array $detail): void
    {
        $this->app->audit()->log($action, $detail, $companyId);
    }
}
