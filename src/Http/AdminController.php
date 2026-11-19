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
        $this->app->db()->transaction(function () use ($id, $uid): void {
            $this->companies->removeUser($id, $uid);
            $this->audit($id, 'admin.user_remove', ['company_id' => $id, 'user_id' => $uid]);
        });
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
            $this->audit($id, 'admin.user_reset_2fa', ['company_id' => $id, 'user_id' => $uid]);
        });
        return Response::redirect('/admin/companies/' . $id);
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

    /** @param array<string, mixed> $company */
    private function detail(array $company, ?string $error, int $status = 200): Response
    {
        return $this->app->render('admin/company.twig', [
            'company' => $company,
            'members' => $this->companies->members((int) $company['id']),
            'error' => $error,
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
     * Direct audit_log insert for now; the Audit service wraps this later.
     *
     * @param array<string, mixed> $detail must never contain secrets
     */
    private function audit(int $companyId, string $action, array $detail): void
    {
        $this->app->db()->exec(
            'INSERT INTO audit_log (company_id, user_id, action, detail, at) VALUES (?, ?, ?, ?, ?)',
            [
                $companyId,
                (int) ($this->app->currentUser()['id'] ?? 0) ?: null,
                $action,
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                gmdate('Y-m-d H:i:s', $this->app->now()),
            ]
        );
    }
}
