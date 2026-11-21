<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\RateLimiter;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ValidationException;
use App\Repo\ExportRepo;
use App\Tenant\CompanyContext;

/**
 * Settings tab "Export" (owner of the active company only, 403 for the 'user' role): GET shows the
 * form, POST builds the ZIP of the ACTIVE company's data and streams it. It works for a suspended
 * company (reading and exporting stay possible, spec 2a): nothing here looks at the access status.
 *
 * POST: CSRF (central), at most 3 exports per hour and company (429 afterwards), options validated
 * before any rate-limit budget is spent, audit row `export.create` (ids and counts only) in the
 * same request; the temp file is a Response::tempFile (streamed and deleted by send()) and is
 * removed right away when anything fails before that.
 */
final class ExportController
{
    public const LIMIT = 3;
    public const WINDOW_SECONDS = 3600;

    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->gate() ?? $this->page(true, '', null);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        if ($denied = $this->gate()) {
            return $denied;
        }
        $ctx = $this->ctx();
        $includePdfs = $request->input('include_pdfs') === '1';
        $year = trim($request->input('year'));
        $options = ['include_pdfs' => $includePdfs, 'year' => $year === '' ? null : $year, 'lang' => $this->app->lang()];
        $exporter = $this->app->exporter();
        try {
            $exporter->check($options);
        } catch (ValidationException $e) {
            return $this->page($includePdfs, $year, $this->app->t((string) array_values($e->errors)[0]), 422);
        }
        $limiter = new RateLimiter($this->app->db(), self::LIMIT, self::WINDOW_SECONDS, fn (): int => $this->app->now());
        $key = 'export:company:' . $ctx->id();
        if ($limiter->tooMany($key)) {
            return $this->page($includePdfs, $year, $this->app->t('export.err_rate'), 429);
        }
        $limiter->hit($key);

        $path = $exporter->build($options);
        try {
            $stats = $exporter->stats();
            $this->app->audit()->log('export.create', [
                'include_pdfs' => $includePdfs,
                'year' => $year === '' ? null : (int) $year,
                'doc_count' => $stats['doc_count'],
                'pdf_count' => $stats['pdf_count'],
            ], $ctx->id(), $ctx->userId());
            return Response::tempFile($path, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $this->filename($ctx) . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    /** export-<company slug>-<YYYY-MM-DD>.zip: slug is [a-z0-9-] only (umlauts and Polish letters transliterated), 'firma' when nothing is left. */
    private function filename(CompanyContext $ctx): string
    {
        $name = mb_strtolower((string) $ctx->company()['name'], 'UTF-8');
        $name = strtr($name, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
        $slug = trim(substr($slug, 0, 40), '-');
        $date = (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        return 'export-' . ($slug === '' ? 'firma' : $slug) . '-' . $date . '.zip';
    }

    private function page(bool $includePdfs, string $year, ?string $error, int $status = 200): Response
    {
        $ctx = $this->ctx();
        $repo = new ExportRepo($this->app->db(), $ctx);
        $last = $repo->lastExportAt();
        $lastDate = null;
        if ($last !== null) {
            $d = (new \DateTimeImmutable($last, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));
            $lastDate = $d->format($this->app->lang() === 'en' ? 'Y-m-d' : 'd.m.Y');
        }
        $isOwner = $ctx->role() === 'owner';
        /** @var list<array{key: string, label: string, path: string, owner_only: bool}> $all */
        $all = require dirname(__DIR__, 2) . '/config/settings_tabs.php';
        return $this->app->render('settings/export.twig', [
            'tabs' => array_values(array_filter($all, fn (array $t): bool => $isOwner || !$t['owner_only'])),
            'active_tab' => 'export',
            'company_name' => (string) $ctx->company()['name'],
            'years' => $repo->documentYears(),
            'selected_year' => $year,
            'include_pdfs' => $includePdfs,
            'last_export' => $lastDate,
            'error' => $error,
            'max_pdfs' => (int) $this->app->config('export_max_pdfs', \App\Domain\Exporter::MAX_PDFS),
        ], $status);
    }

    private function gate(): ?Response
    {
        if ($this->ctx()->role() === 'owner') {
            return null;
        }
        return $this->app->render('error.twig', ['status' => 403, 'message' => $this->app->t('error.forbidden'), 'detail' => null], 403);
    }

    private function ctx(): CompanyContext
    {
        return $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
    }
}
