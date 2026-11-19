<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ConfigureResult;
use App\Domain\DocType;
use App\Domain\InvalidNumberFormat;
use App\Domain\NumberFormat;
use App\Domain\NumberSequences;
use App\Domain\Percent;
use App\Domain\ValidationException;
use App\Money\Formatter;
use App\Repo\CompanySettingsRepo;
use App\Auth\UserService;
use App\Storage\LogoStore;
use App\Tenant\CompanyContext;

/**
 * Settings area. Tabs are defined in config/settings_tabs.php. Company, dunning and mail tabs
 * (and the logo routes) are for the 'owner' role of the active company only (403 for 'user');
 * the security tab is personal and open to every member. All routes sit behind RequireCompany;
 * the company is always the context company, no id is ever read from the request.
 *
 * Logo upload: the temp file must come from PHP's upload handling (is_uploaded_file). Tests set
 * the config key `trust_upload_paths` to feed plain files; it must stay off in production.
 */
final class SettingsController
{
    /** Failed password checks (backup codes and password change share it) per user and window. */
    private const PASSWORD_LIMIT_KEY = 'security:user:';

    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        foreach ($this->tabs() as $tab) {
            return Response::redirect($tab['path']);
        }
        return $this->forbidden();
    }

    // ---- company ----

    /** @param array<string, string> $params */
    public function company(Request $request, array $params): Response
    {
        return $this->gate() ?? $this->companyPage($this->companyForm($this->repo()->get()), [], 200, $request->queryParam('saved') === '1');
    }

    /** @param array<string, string> $params */
    public function saveCompany(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $in = $this->fields($request, ['name', 'address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'default_lang', 'default_currency', 'default_payment_days', 'intro_text', 'outro_text', 'footer_text']);
        try {
            $this->repo()->saveCompany($in);
        } catch (ValidationException $e) {
            return $this->companyPage($in, $e->errors, 422);
        }
        return Response::redirect('/settings/company?saved=1');
    }

    /** @param array<string, string> $params */
    public function uploadLogo(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $ctx = $this->ctx();
        $store = $this->logos();
        try {
            // PHP deletes its own upload temp file at the end of the request.
            $tmp = $this->uploadedPath($request->files['logo'] ?? null);
            $image = $store->process($tmp);
            $path = $store->save($ctx->id(), $image['bytes'], $image['ext']);
            $this->repo()->setLogo($path);
        } catch (ValidationException $e) {
            return $this->companyPage($this->companyForm($this->repo()->get()), $e->errors, 422);
        }
        return Response::redirect('/settings/company?saved=1');
    }

    /** @param array<string, string> $params */
    public function removeLogo(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $this->logos()->delete($this->ctx()->id());
        $this->repo()->setLogo(null);
        return Response::redirect('/settings/company?saved=1');
    }

    /** The context company's logo (never another company's; never a path from the request). @param array<string, string> $params */
    public function logo(Request $request, array $params): Response
    {
        $ctx = $this->ctx();
        $file = $this->logos()->locate($ctx->id(), $this->repo()->get()['logo_path'] ?? null);
        if ($file === null || ($bytes = file_get_contents($file)) === false) {
            return $this->notFound();
        }
        return (new Response($bytes, 200, [
            'Content-Type' => LogoStore::mimeFor($file),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline',
        ]));
    }

    // ---- numbering ----

    /** @param array<string, string> $params */
    public function numbers(Request $request, array $params): Response
    {
        return $this->gate() ?? $this->numbersPage($this->numbersForm(), [], 200, $request->queryParam('saved') === '1');
    }

    /**
     * Saves all four document types in ONE transaction: if any row is invalid nothing is saved
     * (422, typed values kept, red line per row). A successful save with warnings/notes (yearly
     * reset forced off, skipped numbers) re-renders directly so the orange lines are visible;
     * otherwise it redirects.
     *
     * @param array<string, string> $params
     */
    public function saveNumbers(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $form = [];
        foreach (DocType::ALL as $type) {
            $form[$type] = [
                'pattern' => $request->input('pattern_' . $type),
                'reset' => $request->input('reset_' . $type) === '1',
                'next' => trim($request->input('next_' . $type)),
                'next_shown' => trim($request->input('next_shown_' . $type)),
            ];
        }
        $seq = $this->sequences();
        $today = $this->berlinNow();
        /** @var array<string, array{pattern?: string, next_value?: string}> $errors */
        $errors = [];
        /** @var array<string, ConfigureResult> $results */
        $results = [];
        try {
            $this->app->db()->transaction(function () use ($form, $seq, $today, &$errors, &$results): void {
                foreach ($form as $type => $row) {
                    try {
                        $results[$type] = $seq->configure($type, $row['pattern'], $row['reset'], $this->nextValue($row), $today);
                    } catch (InvalidNumberFormat $e) {
                        $errors[$type]['pattern'] = $e->translationKey();
                    } catch (ValidationException $e) {
                        $errors[$type] = array_merge($errors[$type] ?? [], $e->errors);
                    }
                }
                if ($errors !== []) {
                    throw new NumbersRollback(); // nothing of this save may stay
                }
            });
        } catch (NumbersRollback) {
            $display = [];
            foreach ($form as $type => $row) {
                $display[$type] = ['pattern' => $row['pattern'], 'reset' => $row['reset'], 'next' => $row['next'], 'next_shown' => $row['next_shown']];
            }
            return $this->numbersPage($display, $errors, 422);
        }
        $flags = [];
        foreach ($results as $type => $result) {
            $flags[$type] = [
                'warnings' => array_map(fn (string $w): string => 'settings.warn_no_year', $result->warnings),
                'notes' => array_map(fn (string $n): string => 'settings.note_skips_numbers', $result->notes),
            ];
        }
        if (array_filter($flags, fn (array $f): bool => $f['warnings'] !== [] || $f['notes'] !== []) === []) {
            return Response::redirect('/settings/numbers?saved=1');
        }
        return $this->numbersPage($this->numbersForm(), [], 200, true, $flags);
    }

    // ---- dunning ----

    /** @param array<string, string> $params */
    public function dunning(Request $request, array $params): Response
    {
        return $this->gate() ?? $this->dunningPage($this->dunningForm($this->repo()->get()), [], 200, $request->queryParam('saved') === '1');
    }

    /** @param array<string, string> $params */
    public function saveDunning(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $in = $this->fields($request, ['dunning_d0', 'dunning_d1', 'dunning_d2', 'dunning_d3', 'dunning_base_rate', 'dunning_b2b', 'dunning_b2c', 'dunning_flat', 'dunning_fee']);
        try {
            $this->repo()->saveDunning($in, $this->app->lang());
        } catch (ValidationException $e) {
            return $this->dunningPage($in, $e->errors, 422);
        }
        return Response::redirect('/settings/dunning?saved=1');
    }

    // ---- mail ----

    /** @param array<string, string> $params */
    public function mail(Request $request, array $params): Response
    {
        return $this->gate() ?? $this->mailPage($this->mailForm($this->repo()->get()), [], 200, $request->queryParam('saved') === '1');
    }

    /** @param array<string, string> $params */
    public function saveMail(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $in = $this->fields($request, ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_name', 'smtp_from_addr']);
        try {
            $this->repo()->saveMail($in);
        } catch (ValidationException $e) {
            unset($in['smtp_pass']); // never echoed back
            return $this->mailPage($in, $e->errors, 422);
        }
        return Response::redirect('/settings/mail?saved=1');
    }

    // ---- security (personal) ----

    /** @param array<string, string> $params */
    public function security(Request $request, array $params): Response
    {
        return $this->securityPage(passwordChanged: $request->queryParam('pw') === '1');
    }

    /** @param array<string, string> $params */
    public function regenerateCodes(Request $request, array $params): Response
    {
        $userId = $this->ctx()->userId();
        $problem = $this->checkCurrentPassword($request->input('current_password'), $userId);
        if ($problem !== null) {
            return $this->securityPage(codesErrors: ['current_password' => $problem[0]], status: $problem[1]);
        }
        $codes = $this->app->backupCodes()->generate($userId);
        $this->app->rateLimiter()->clear(self::PASSWORD_LIMIT_KEY . $userId);
        $this->audit('security.backup_codes_regenerate', []);
        // Rendered directly like the 2FA setup page: the plain codes are shown exactly once.
        return $this->securityPage(newCodes: $codes)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function changePassword(Request $request, array $params): Response
    {
        $userId = $this->ctx()->userId();
        $current = $request->input('current_password');
        $problem = $this->checkCurrentPassword($current, $userId);
        if ($problem !== null) {
            return $this->securityPage(passwordErrors: ['current_password' => $problem[0]], status: $problem[1]);
        }
        $new = $request->input('new_password');
        $errors = [];
        if (mb_strlen($new, 'UTF-8') < UserService::MIN_PASSWORD_LENGTH || strlen($new) > 1024) {
            $errors['new_password'] = 'security.err_password_short';
        } elseif (hash_equals($current, $new)) {
            $errors['new_password'] = 'security.err_password_same';
        }
        if (!hash_equals($new, $request->input('new_password_confirm'))) {
            $errors['new_password_confirm'] = 'security.err_password_mismatch';
        }
        if ($errors !== []) {
            return $this->securityPage(passwordErrors: $errors, status: 422);
        }
        $this->app->db()->transaction(function () use ($userId, $new): void {
            $this->app->db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $userId]);
            $this->audit('security.password_change', []);
        });
        $this->app->rateLimiter()->clear(self::PASSWORD_LIMIT_KEY . $userId);
        // Stay logged in, but a stolen old session id or form token is worthless from now on.
        $this->app->session()->regenerate();
        $this->app->csrf()->rotate();
        return Response::redirect('/settings/security?pw=1');
    }

    // ---- helpers ----

    /** @return list<array{key: string, label: string, path: string, owner_only: bool}> tabs the current role may use */
    private function tabs(): array
    {
        $isOwner = $this->ctx()->role() === 'owner';
        /** @var list<array{key: string, label: string, path: string, owner_only: bool}> $all */
        $all = require dirname(__DIR__, 2) . '/config/settings_tabs.php';
        return array_values(array_filter($all, fn (array $t): bool => $isOwner || !$t['owner_only']));
    }

    /** 403 page unless the user is 'owner' of the active company. */
    private function gate(): ?Response
    {
        return $this->ctx()->role() === 'owner' ? null : $this->forbidden();
    }

    private function ctx(): CompanyContext
    {
        return $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
    }

    private function repo(): CompanySettingsRepo
    {
        return new CompanySettingsRepo($this->app->db(), $this->ctx(), $this->app->crypto(), fn (): int => $this->app->now());
    }

    private function logos(): LogoStore
    {
        $path = (string) $this->app->config('storage_path', dirname(__DIR__, 2) . '/storage');
        return new LogoStore(rtrim($path, '/'));
    }

    /**
     * @param list<string> $names
     * @return array<string, string> only the listed POST fields (everything else, e.g. id, company_id, is ignored)
     */
    private function fields(Request $request, array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = $request->input($n);
        }
        return $out;
    }

    /** @throws ValidationException when there is no usable uploaded file */
    private function uploadedPath(mixed $file): string
    {
        if (!is_array($file)) {
            throw new ValidationException(['logo' => 'settings.err_logo_missing']);
        }
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['logo' => 'settings.err_logo_missing']);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['logo' => 'settings.err_logo_size']);
        }
        $tmp = $file['tmp_name'] ?? '';
        $trusted = $this->app->config('trust_upload_paths', false) === true;
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !($trusted ? is_file($tmp) : is_uploaded_file($tmp))) {
            throw new ValidationException(['logo' => 'settings.err_logo_invalid']);
        }
        return $tmp;
    }

    /**
     * Current-password check with a per-user failure limit (5 per 15 minutes).
     *
     * @return array{0: string, 1: int}|null [translation key, HTTP status] or null when correct
     */
    private function checkCurrentPassword(string $password, int $userId): ?array
    {
        $limiter = $this->app->rateLimiter();
        $key = self::PASSWORD_LIMIT_KEY . $userId;
        if ($limiter->tooMany($key)) {
            return ['security.err_locked', 429];
        }
        $hash = (string) ($this->app->db()->one('SELECT password_hash FROM users WHERE id = ?', [$userId])['password_hash'] ?? '');
        if ($password === '' || strlen($password) > 1024 || !password_verify($password, $hash)) {
            $limiter->hit($key);
            return ['security.err_current_password', 422];
        }
        return null;
    }

    /** @param array<string, mixed> $detail */
    private function audit(string $action, array $detail): void
    {
        $ctx = $this->ctx();
        $this->app->db()->exec(
            'INSERT INTO audit_log (company_id, user_id, action, detail, at) VALUES (?, ?, ?, ?, ?)',
            [$ctx->id(), $ctx->userId(), $action, json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', $this->app->now())]
        );
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function companyForm(array $row): array
    {
        $form = [];
        foreach (['name', 'address', 'tax_number', 'vat_id', 'iban', 'bic', 'bank', 'default_lang', 'default_currency', 'default_payment_days', 'intro_text', 'outro_text', 'footer_text'] as $f) {
            $form[$f] = (string) ($row[$f] ?? '');
        }
        return $form;
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function dunningForm(array $row): array
    {
        $lang = $this->app->lang();
        $fmt = new Formatter();
        $bp = $row['dunning_base_rate_bp'];
        return [
            'dunning_d0' => (string) $row['dunning_d0'], 'dunning_d1' => (string) $row['dunning_d1'],
            'dunning_d2' => (string) $row['dunning_d2'], 'dunning_d3' => (string) $row['dunning_d3'],
            'dunning_base_rate' => $bp === null ? '' : Percent::format((int) $bp, $lang),
            'dunning_b2b' => Percent::format((int) $row['dunning_b2b_surcharge_bp'], $lang),
            'dunning_b2c' => Percent::format((int) $row['dunning_b2c_surcharge_bp'], $lang),
            'dunning_flat' => $fmt->decimalAmount((int) $row['dunning_flat_minor'], $lang),
            'dunning_fee' => $fmt->decimalAmount((int) $row['dunning_fee_minor'], $lang),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function mailForm(array $row): array
    {
        $form = [];
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from_name', 'smtp_from_addr'] as $f) {
            $form[$f] = (string) ($row[$f] ?? '');
        }
        return $form;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function page(string $template, string $activeTab, array $vars, int $status): Response
    {
        $vars += [
            'tabs' => $this->tabs(),
            'active_tab' => $activeTab,
            'company_name' => (string) $this->ctx()->company()['name'],
        ];
        return $this->app->render($template, $vars, $status);
    }

    /** @param array<string, string> $errors */
    private function translate(array $errors): array
    {
        return array_map(fn (string $key): string => $this->app->t($key), $errors);
    }

    /**
     * The "next number" input as an int, or null when it is empty or still what the form showed
     * (so a stale but untouched value cannot fail a save after another document was issued).
     * Anything that is not plain digits is passed as 0, which configure() rejects as out of range.
     *
     * @param array{next: string, next_shown: string} $row
     */
    private function nextValue(array $row): ?int
    {
        if ($row['next'] === '' || $row['next'] === $row['next_shown']) {
            return null;
        }
        return preg_match('/\A[0-9]{1,10}\z/', $row['next']) === 1 ? (int) $row['next'] : 0;
    }

    private function sequences(): NumberSequences
    {
        return new NumberSequences($this->app->db(), $this->ctx(), fn (): int => $this->app->now());
    }

    /** The current business date: Europe/Berlin, which decides the year bucket and the date tokens. */
    private function berlinNow(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone(new \DateTimeZone('Europe/Berlin'));
    }

    /** @return array<string, array{pattern: string, reset: bool, next: string, next_shown: string}> the saved state */
    private function numbersForm(): array
    {
        $seq = $this->sequences();
        $today = $this->berlinNow();
        $form = [];
        foreach (DocType::ALL as $type) {
            $f = $seq->format($type);
            $next = (string) ($seq->lastCounter($type, $today) + 1);
            $form[$type] = ['pattern' => $f['pattern'], 'reset' => $f['reset_yearly'], 'next' => $next, 'next_shown' => $next];
        }
        return $form;
    }

    /**
     * @param array<string, array{pattern: string, reset: bool, next: string, next_shown: string}> $form
     * @param array<string, array<string, string>> $errors doc type => field => translation key
     * @param array<string, array{warnings: list<string>, notes: list<string>}> $flags
     */
    private function numbersPage(array $form, array $errors, int $status = 200, bool $saved = false, array $flags = []): Response
    {
        $seq = $this->sequences();
        $today = $this->berlinNow();
        $rows = [];
        foreach (DocType::ALL as $type) {
            $rows[] = [
                'type' => $type,
                'label' => 'settings.doctype_' . ($type === DocType::CREDIT_NOTE ? 'correction' : $type),
                'pattern' => $form[$type]['pattern'],
                'reset' => $form[$type]['reset'],
                'next' => $form[$type]['next'],
                'next_shown' => $form[$type]['next_shown'],
                'preview' => $seq->preview($type, $today), // of the SAVED format, never consumed
                'error' => isset($errors[$type]['pattern']) ? $this->app->t($errors[$type]['pattern']) : null,
                'next_error' => isset($errors[$type]['next_value']) ? $this->app->t($errors[$type]['next_value']) : null,
                'warnings' => array_map(fn (string $k): string => $this->app->t($k), $flags[$type]['warnings'] ?? []),
                'notes' => array_map(fn (string $k): string => $this->app->t($k), $flags[$type]['notes'] ?? []),
            ];
        }
        return $this->page('settings/numbers.twig', 'numbers', [
            'rows' => $rows,
            'has_errors' => $errors !== [],
            'saved' => $saved,
            'max_pattern' => NumberFormat::MAX_PATTERN_LENGTH,
        ], $status);
    }

    /** @param array<string, string> $form @param array<string, string> $errors */
    private function companyPage(array $form, array $errors, int $status = 200, bool $saved = false): Response
    {
        $row = $this->repo()->get();
        $logo = $this->logos()->locate($this->ctx()->id(), $row['logo_path'] ?? null);
        return $this->page('settings/company.twig', 'company', [
            'form' => $form,
            'errors' => $this->translate($errors),
            'saved' => $saved,
            'langs' => CompanySettingsRepo::LANGS,
            'currencies' => CompanySettingsRepo::CURRENCIES,
            'has_logo' => $logo !== null,
            'logo_version' => $logo === null ? 0 : (int) filemtime($logo),
        ], $status);
    }

    /** @param array<string, string> $form @param array<string, string> $errors */
    private function dunningPage(array $form, array $errors, int $status = 200, bool $saved = false): Response
    {
        return $this->page('settings/dunning.twig', 'dunning', [
            'form' => $form,
            'errors' => $this->translate($errors),
            'saved' => $saved,
        ], $status);
    }

    /** @param array<string, string> $form @param array<string, string> $errors */
    private function mailPage(array $form, array $errors, int $status = 200, bool $saved = false): Response
    {
        $repo = $this->repo();
        return $this->page('settings/mail.twig', 'mail', [
            'form' => $form,
            'errors' => $this->translate($errors),
            'saved' => $saved,
            'pass_set' => $repo->get()['smtp_pass_set'],
            'configured' => $repo->mailConfigured(),
        ], $status);
    }

    /**
     * @param list<string>|null $newCodes plain backup codes, shown once
     * @param array<string, string> $codesErrors
     * @param array<string, string> $passwordErrors
     */
    private function securityPage(?array $newCodes = null, array $codesErrors = [], array $passwordErrors = [], int $status = 200, bool $passwordChanged = false): Response
    {
        $user = $this->app->currentUser();
        return $this->page('settings/security.twig', 'security', [
            'totp_active' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'new_codes' => $newCodes,
            'codes_errors' => $this->translate($codesErrors),
            'password_errors' => $this->translate($passwordErrors),
            'password_changed' => $passwordChanged,
        ], $status);
    }

    private function forbidden(): Response
    {
        return $this->app->render('error.twig', ['status' => 403, 'message' => $this->app->t('error.forbidden'), 'detail' => null], 403);
    }

    private function notFound(): Response
    {
        return $this->app->render('error.twig', ['status' => 404, 'message' => $this->app->t('error.not_found'), 'detail' => null], 404);
    }
}
