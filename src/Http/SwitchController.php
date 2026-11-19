<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\I18n\Translator;
use App\Tenant\AccessDenied;
use App\Tenant\CompanyContext;

/**
 * Switchers of the shell: UI language (POST /lang) and active company (GET /company/choose,
 * POST /company/switch). The theme is client-side only (public/assets/app.js).
 * CSRF is checked centrally by App for every POST.
 */
final class SwitchController
{
    public function __construct(private App $app)
    {
    }

    /**
     * Sets the session language and, when someone is logged in, users.ui_lang. Open route, so
     * the login page can switch language too.
     *
     * @param array<string, string> $params
     */
    public function language(Request $request, array $params): Response
    {
        $lang = $request->input('lang');
        if (!in_array($lang, Translator::SUPPORTED, true)) {
            return $this->error(422, 'error.bad_request');
        }
        $this->app->session()->set('lang', $lang);
        $user = $this->app->currentUser();
        if ($user !== null) {
            $this->app->db()->exec('UPDATE users SET ui_lang = ? WHERE id = ?', [$lang, (int) $user['id']]);
        }
        return Response::redirect(BackPath::sanitize($request->input('back')));
    }

    /**
     * Lists the companies the user belongs to (existing companies only).
     *
     * @param array<string, string> $params
     */
    public function choose(Request $request, array $params): Response
    {
        $user = $this->app->currentUser();
        return $this->app->render('company_choose.twig', [
            'companies' => Shell::companiesOf($this->app->db(), (int) $user['id']),
            'selected' => $this->app->context()?->id(),
        ]);
    }

    /**
     * Selects the active company after verifying membership; never trusts the posted id.
     *
     * @param array<string, string> $params
     */
    public function switch(Request $request, array $params): Response
    {
        $user = $this->app->currentUser();
        $raw = $request->input('company_id');
        if (preg_match('/\A[1-9][0-9]{0,17}\z/', $raw) !== 1) {
            return $this->error(403, 'error.forbidden');
        }
        try {
            $ctx = CompanyContext::forUser($this->app->db(), (int) $user['id'], (int) $raw);
        } catch (AccessDenied) {
            return $this->error(403, 'error.forbidden');
        }
        $this->app->session()->setCompanyId($ctx->id());
        // The context is cached per request: redirect so the next request resolves it afresh.
        return Response::redirect(BackPath::sanitize($request->input('back')));
    }

    private function error(int $status, string $key): Response
    {
        return $this->app->render('error.twig', ['status' => $status, 'message' => $this->app->t($key), 'detail' => null], $status);
    }
}
