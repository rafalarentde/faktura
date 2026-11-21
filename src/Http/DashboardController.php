<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\ReportRepo;

/**
 * Start page (GET /, behind RequireCompany): four summary cards (open, overdue, due within 7 days,
 * paid this month) and the recent documents. Figures come from ReportRepo (always per currency;
 * the card shows the company's main currency, other currencies as extra lines, never summed).
 * ?tab=all|invoices|offers filters the recent panel; anything else means all.
 */
final class DashboardController
{
    private const TABS = ['all' => null, 'invoices' => 'invoice', 'offers' => 'offer'];
    private const RECENT = 10;

    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $ctx = $this->app->context() ?? throw new \LogicException('No company selected.');
        $report = new ReportRepo($this->app->db(), $ctx);
        $today = new \DateTimeImmutable('@' . $this->app->now());
        $berlin = $today->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $fmt = new Formatter();
        $lang = $this->app->lang();

        $tab = $request->queryParam('tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'all';
        $recent = $report->recent(self::RECENT, self::TABS[$tab], $today);
        $empty = $recent === [] && ($tab === 'all' || !$report->hasDocuments());

        return $this->app->render('dashboard.twig', [
            'empty' => $empty,
            'period' => $fmt->month($berlin, $lang, true),
            'cards' => $empty ? [] : $this->cards($report->totals($today), $fmt->month($berlin, $lang), $fmt, $lang),
            'tab' => $tab,
            'tabs' => array_keys(self::TABS),
            'rows' => array_map(fn (array $r): array => $this->row($r, $fmt, $lang), $recent),
        ]);
    }

    /**
     * @param array<string, mixed> $t ReportRepo::totals()
     * @return list<array<string, mixed>>
     */
    private function cards(array $t, string $month, Formatter $fmt, string $lang): array
    {
        $primary = (string) $t['primaryCurrency'];
        $defs = [
            ['open', 'card-y', $this->app->t('dash.card_open'), 'dash.invoices_count'],
            ['overdue', 'card-late', $this->app->t('dash.card_overdue'), 'dash.invoices_count'],
            ['due_in_7', 'card-soon', $this->app->t('dash.card_due_soon'), 'dash.invoices_count'],
            ['paid_month', 'card-ok', $this->app->t('dash.card_paid', ['month' => $month]), 'dash.payments_count'],
        ];
        $cards = [];
        foreach ($defs as [$key, $class, $label, $subKey]) {
            $figures = $t[$key];
            $main = $figures[$primary] ?? ['minor' => 0, 'count' => 0];
            $others = [];
            foreach ($t['otherCurrencies'] as $cur) {
                if (isset($figures[$cur])) {
                    $others[] = '+ ' . $fmt->money(Money::of($figures[$cur]['minor'], $cur), $lang);
                }
            }
            $count = array_sum(array_column($figures, 'count')); // a count of documents, not money: all currencies
            $late = $key === 'overdue' && $count > 0;
            $cards[] = [
                'key' => $key,
                'class' => $class,
                'label' => $label,
                'amount' => $fmt->money(Money::of($main['minor'], $primary), $lang),
                'late' => $key === 'overdue' && $main['minor'] > 0,
                'others' => $others,
                'sub' => $this->app->t($late ? 'dash.card_overdue_sub' : $subKey, ['count' => $count]),
                'link' => $late ? '/dunning' : null,
            ];
        }
        return $cards;
    }

    /**
     * @param array<string, mixed> $r ReportRepo::recent() row
     * @return array<string, mixed>
     */
    private function row(array $r, Formatter $fmt, string $lang): array
    {
        $offer = $r['type'] === 'offer';
        return [
            'href' => $r['href'],
            'number' => $r['number'],
            'buyer' => $r['buyer_name'],
            'type_label' => $this->app->t('invoice.type_' . $r['type']),
            'issue_date' => $r['issue_date'] === null ? null : $fmt->date(new \DateTimeImmutable($r['issue_date']), $lang),
            'due_date' => $r['due_date'] === null ? null : $fmt->date(new \DateTimeImmutable($r['due_date']), $lang),
            'amount' => $fmt->money(Money::of($r['gross_minor'], $r['currency']), $lang),
            'status' => $r['status'],
            'status_label' => $this->app->t(($offer ? 'offer.status_' : 'invoice.status_') . $r['status']),
            'chip' => $offer ? StatusChips::offer($r['status']) : StatusChips::invoice($r['status']),
        ];
    }
}
