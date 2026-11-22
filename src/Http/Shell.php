<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Db;
use App\Money\Formatter;
use App\Repo\ReportRepo;

/**
 * Data for the application shell (templates/layout.twig): navigation, user, active company,
 * promo switch. Exposed to Twig as the shell() function (computed once per request), so
 * controllers do not have to pass layout variables themselves.
 */
final class Shell
{
    public function __construct(private App $app)
    {
    }

    /**
     * @return array{path: string, user: array<string, mixed>|null, is_admin: bool, nav: list<array<string, mixed>>,
     *               company: array<string, mixed>|null, company_count: int, promo: bool}
     */
    public function data(): array
    {
        $path = $this->app->request()->path;
        $user = $this->app->currentUser();
        $isAdmin = $user !== null && (int) $user['is_admin'] === 1;
        $context = $user === null ? null : $this->app->context();
        $company = $context?->company();
        return [
            'path' => $path,
            'user' => $user,
            'is_admin' => $isAdmin,
            'nav' => self::navItems($this->loadNav(), $path, $isAdmin),
            'company' => $company === null ? null : [
                'id' => (int) $company['id'],
                'name' => (string) $company['name'],
                'plan' => (string) $company['plan'],
                'access_status' => (string) $company['access_status'],
                'access_until' => $company['access_until'] === null ? null : substr((string) $company['access_until'], 0, 10),
            ] + $this->planBox((string) $company['plan']),
            'company_count' => $user === null ? 0 : count(self::companiesOf($this->app->db(), (int) $user['id'])),
            'promo' => (bool) $this->app->config('promo_enabled', false),
        ];
    }

    /**
     * Plan box data: the plan's label, the documents issued in the current Berlin month (ONE count
     * query; invoices, credit notes, offers and dunning letters by issue_date; informational), an
     * optional limit (config plan_limits, plan => int; default none, nothing is enforced) and an
     * optional upgrade link (config upgrade_url, https only; shown for the free plan).
     *
     * @return array{plan_label: string, docs_month: int, docs_month_name: string, plan_limit: int|null, upgrade_url: string|null}
     */
    private function planBox(string $plan): array
    {
        $today = new \DateTimeImmutable('@' . $this->app->now());
        $limits = $this->app->config('plan_limits', []);
        $limit = is_array($limits) && isset($limits[$plan]) && is_int($limits[$plan]) && $limits[$plan] > 0 ? $limits[$plan] : null;
        $url = $this->app->config('upgrade_url', '');
        $url = is_string($url) && preg_match('#\Ahttps://[^\s"<>\'\\\\]+\z#', $url) === 1 ? $url : null;
        return [
            'plan_label' => in_array($plan, ['free', 'standard', 'pro'], true) ? $this->app->t('nav.plan_' . $plan) : $this->app->t('nav.plan_named', ['plan' => $plan]),
            'docs_month' => (new ReportRepo($this->app->db(), $this->app->context() ?? throw new \LogicException('No company.')))->issuedInMonth($today),
            'docs_month_name' => (new Formatter())->month($today->setTimezone(new \DateTimeZone('Europe/Berlin')), $this->app->lang()),
            'plan_limit' => $limit,
            'upgrade_url' => $plan === 'free' ? $url : null,
        ];
    }

    /**
     * Companies the user may work in: a company_users row AND an existing company.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function companiesOf(Db $db, int $userId): array
    {
        $rows = $db->all(
            'SELECT c.id, c.name FROM company_users cu JOIN companies c ON c.id = cu.company_id WHERE cu.user_id = ? ORDER BY c.name, c.id',
            [$userId]
        );
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * Visible navigation for $path: drops disabled and (for non-admins) admin-only items,
     * collapses leading, trailing and doubled separators, and marks the active item. An item
     * is active when the path equals its prefix ('match', else 'path') or continues it with
     * a '/'; '/' only matches itself.
     *
     * @param list<array<string, mixed>> $items config/nav.php
     * @return list<array<string, mixed>> items get an 'active' bool; separators stay ['separator' => true]
     */
    public static function navItems(array $items, string $path, bool $isAdmin): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($item['separator'] ?? false) === true) {
                if ($out !== [] && !(end($out)['separator'] ?? false)) {
                    $out[] = ['separator' => true];
                }
                continue;
            }
            if (($item['enabled'] ?? false) !== true || (($item['admin_only'] ?? false) === true && !$isAdmin)) {
                continue;
            }
            $prefix = (string) ($item['match'] ?? $item['path']);
            $item['active'] = $prefix === '/'
                ? $path === '/'
                : ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/'));
            $out[] = $item;
        }
        if ($out !== [] && (end($out)['separator'] ?? false)) {
            array_pop($out);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function loadNav(): array
    {
        $nav = require dirname(__DIR__, 2) . '/config/nav.php';
        return is_array($nav) ? array_values($nav) : [];
    }
}
