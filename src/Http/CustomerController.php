<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Countries;
use App\Domain\ValidationException;
use App\Domain\VatId;
use App\Repo\CustomerRepo;
use App\Vies\ViesStatus;

/**
 * Customer register (list on top, edit form below) and the VIES check. All routes sit behind
 * RequireCompany; the repo is scoped to the context company, so an id of another company simply
 * is not found (404, existence is never revealed). There is no delete in v1.
 */
final class CustomerController
{
    private const PAYMENT_DAY_CHOICES = [7, 14, 21, 30, 45, 60, 90];
    private const FORM_FIELDS = ['name', 'address', 'country', 'vat_id', 'email', 'default_lang', 'default_currency', 'payment_days', 'notes'];

    public function __construct(private App $app)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, null, $this->emptyForm());
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $customer = $this->repo()->find((int) $params['id']);
        if ($customer === null) {
            return $this->notFound();
        }
        return $this->page($request, $customer, $this->formFrom($customer), saved: $request->queryParam('saved') === '1');
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        try {
            $id = $this->repo()->save($this->input($request));
        } catch (ValidationException $e) {
            return $this->page($request, null, $this->formFromInput($request), $e->errors, 422);
        }
        return Response::redirect('/customers/' . $id . '?saved=1');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $customer = $this->repo()->find($id);
        if ($customer === null) {
            return $this->notFound();
        }
        try {
            $this->repo()->save($this->input($request), $id);
        } catch (ValidationException $e) {
            return $this->page($request, $customer, $this->formFromInput($request), $e->errors, 422);
        }
        return Response::redirect('/customers/' . $id . '?saved=1');
    }

    /** @param array<string, string> $params */
    public function vies(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $customer = $this->repo()->find($id);
        if ($customer === null) {
            return $this->notFound();
        }
        $vatId = (string) ($customer['vat_id'] ?? '');
        if ($vatId === '') {
            return $this->page($request, $customer, $this->formFrom($customer), ['vat_id' => 'customer.err_vies_no_vat'], 422);
        }
        $country = (string) $customer['country'];
        $status = $this->app->vies()->check(VatId::prefix($country), VatId::number($country, $vatId));
        $this->repo()->recordVies($id, $status, (new \DateTimeImmutable('@' . $this->app->now())));
        return Response::redirect('/customers/' . $id);
    }

    private function repo(): CustomerRepo
    {
        $ctx = $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
        return new CustomerRepo($this->app->db(), $ctx);
    }

    /** @return array<string, mixed> only the fields the form owns; ids and company_id are never read from input */
    private function input(Request $request): array
    {
        $data = [];
        foreach (self::FORM_FIELDS as $f) {
            $data[$f] = $request->input($f);
        }
        $data['is_company'] = $request->input('is_company', '1') === '0' ? '0' : '1';
        return $data;
    }

    /** @return array<string, mixed> */
    private function emptyForm(): array
    {
        $company = ($this->app->context() ?? throw new \LogicException('No company.'))->company();
        $lang = (string) ($company['default_lang'] ?? 'de');
        $currency = (string) ($company['default_currency'] ?? 'EUR');
        return [
            'name' => '', 'address' => '', 'country' => 'DE', 'is_company' => true, 'vat_id' => '', 'email' => '',
            'default_lang' => in_array($lang, CustomerRepo::LANGS, true) ? $lang : 'de',
            'default_currency' => in_array($currency, CustomerRepo::CURRENCIES, true) ? $currency : 'EUR',
            'payment_days' => (string) (int) ($company['default_payment_days'] ?? 14),
            'notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formFrom(array $row): array
    {
        return [
            'name' => (string) $row['name'], 'address' => (string) ($row['address'] ?? ''), 'country' => (string) $row['country'],
            'is_company' => (int) $row['is_company'] === 1, 'vat_id' => (string) ($row['vat_id'] ?? ''),
            'email' => (string) ($row['email'] ?? ''), 'default_lang' => (string) $row['default_lang'],
            'default_currency' => (string) $row['default_currency'], 'payment_days' => (string) (int) $row['payment_days'],
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    /** @return array<string, mixed> the values as typed, for re-rendering after an error */
    private function formFromInput(Request $request): array
    {
        $in = $this->input($request);
        $in['is_company'] = $in['is_company'] === '1';
        return $in;
    }

    /**
     * @param array<string, mixed>|null $customer the saved row shown in the form (null = new customer)
     * @param array<string, mixed> $form
     * @param array<string, string> $errors field => translation key
     */
    private function page(Request $request, ?array $customer, array $form, array $errors = [], int $status = 200, bool $saved = false): Response
    {
        $q = trim($request->queryParam('q'));
        $messages = array_map(fn (string $key): string => $this->app->t($key), $errors);
        $days = self::PAYMENT_DAY_CHOICES;
        $current = (int) ($form['payment_days'] ?? 0);
        if (ctype_digit((string) ($form['payment_days'] ?? '')) && !in_array($current, $days, true) && $current <= 365) {
            $days[] = $current;
            sort($days);
        }
        return $this->app->render('customers/list.twig', [
            'customers' => $this->repo()->list($q),
            'q' => $q,
            'customer' => $customer,
            'form' => $form,
            'errors' => $messages,
            'saved' => $saved,
            'vies' => $customer === null ? null : $this->viesLine($customer),
            'countries' => Countries::all(),
            'langs' => CustomerRepo::LANGS,
            'currencies' => CustomerRepo::CURRENCIES,
            'payment_days' => $days,
        ], $status);
    }

    /**
     * Display-only status line (Task 14 owns the real VAT rule).
     *
     * @param array<string, mixed> $c
     * @return array{kind: string, text: string}|null kind: ok|warn|soft|hint
     */
    private function viesLine(array $c): ?array
    {
        if ((string) ($c['vat_id'] ?? '') === '') {
            return null;
        }
        $status = $c['vies_status'] ?? null;
        $date = $this->checkedDate($c['vies_checked_at'] ?? null);
        $reverse = (string) $c['country'] !== 'DE' && (int) $c['is_company'] === 1;
        if ($status === ViesStatus::Valid->value) {
            if (!$reverse) {
                return ['kind' => 'hint', 'text' => $this->app->t('customer.vies_valid_plain', ['date' => $date['text']])];
            }
            $key = $date['today'] ? 'customer.vies_valid' : 'customer.vies_valid_on';
            return ['kind' => 'ok', 'text' => $this->app->t($key, ['date' => $date['text']])];
        }
        return match ($status) {
            ViesStatus::Invalid->value => ['kind' => 'warn', 'text' => $this->app->t('customer.vies_invalid', ['date' => $date['text']])],
            ViesStatus::Unavailable->value => ['kind' => 'soft', 'text' => $this->app->t('customer.vies_unavailable')],
            default => ['kind' => 'hint', 'text' => $this->app->t('customer.vies_never')],
        };
    }

    /** @return array{text: string, today: bool} the UTC timestamp shown as a Berlin date */
    private function checkedDate(mixed $utc): array
    {
        if (!is_string($utc) || $utc === '') {
            return ['text' => '', 'today' => false];
        }
        $berlin = new \DateTimeZone('Europe/Berlin');
        $at = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($berlin);
        $now = (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone($berlin);
        return [
            'text' => $at->format($this->app->lang() === 'en' ? 'Y-m-d' : 'd.m.Y'),
            'today' => $at->format('Y-m-d') === $now->format('Y-m-d'),
        ];
    }

    private function notFound(): Response
    {
        return $this->app->render('error.twig', [
            'status' => 404,
            'message' => $this->app->t('error.not_found'),
            'detail' => null,
        ], 404);
    }
}
