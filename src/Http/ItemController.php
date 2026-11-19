<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Units;
use App\Domain\ValidationException;
use App\Money\Formatter;
use App\Money\Money;
use App\Repo\ItemRepo;

/**
 * Items and services register (list on top, edit form below). All routes sit behind
 * RequireCompany; the repo is scoped to the context company, so an id of another company simply
 * is not found (404, existence is never revealed). There is no delete in v1: items are
 * deactivated instead, which only hides them from the catalogue pickers.
 */
final class ItemController
{
    private const FORM_FIELDS = ['name_de', 'name_pl', 'name_en', 'type', 'unit', 'price', 'currency', 'vat_bp'];

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
        $item = $this->repo()->find((int) $params['id']);
        if ($item === null) {
            return $this->notFound();
        }
        return $this->page($request, $item, $this->formFrom($item), saved: $request->queryParam('saved') === '1');
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        try {
            $id = $this->repo()->save($this->input($request));
        } catch (ValidationException $e) {
            return $this->page($request, null, $this->input($request), $e->errors, 422);
        }
        return Response::redirect('/items/' . $id . '?saved=1');
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $item = $this->repo()->find($id);
        if ($item === null) {
            return $this->notFound();
        }
        try {
            $this->repo()->save($this->input($request), $id);
        } catch (ValidationException $e) {
            return $this->page($request, $item, $this->input($request), $e->errors, 422);
        }
        return Response::redirect('/items/' . $id . '?saved=1');
    }

    /** POST active=1|0 sets the flag; without it the current state is flipped. @param array<string, string> $params */
    public function active(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $item = $this->repo()->find($id);
        if ($item === null) {
            return $this->notFound();
        }
        $want = $request->input('active', '');
        $active = $want === '1' ? true : ($want === '0' ? false : (int) $item['is_active'] !== 1);
        $this->repo()->setActive($id, $active);
        return Response::redirect('/items');
    }

    private function repo(): ItemRepo
    {
        $ctx = $this->app->context() ?? throw new \LogicException('RequireCompany must run first.');
        return new ItemRepo($this->app->db(), $ctx, $this->app->lang());
    }

    /** @return array<string, string> only the fields the form owns; ids, company_id and the active flag are never read from input */
    private function input(Request $request): array
    {
        $data = [];
        foreach (self::FORM_FIELDS as $f) {
            $data[$f] = $request->input($f);
        }
        return $data;
    }

    /** @return array<string, string> */
    private function emptyForm(): array
    {
        $company = ($this->app->context() ?? throw new \LogicException('No company.'))->company();
        $currency = (string) ($company['default_currency'] ?? 'EUR');
        return [
            'name_de' => '', 'name_pl' => '', 'name_en' => '', 'type' => 'service', 'unit' => 'hour', 'price' => '',
            'currency' => in_array($currency, ItemRepo::CURRENCIES, true) ? $currency : 'EUR', 'vat_bp' => '1900',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function formFrom(array $item): array
    {
        return [
            'name_de' => (string) ($item['names']['de']['name'] ?? ''),
            'name_pl' => (string) ($item['names']['pl']['name'] ?? ''),
            'name_en' => (string) ($item['names']['en']['name'] ?? ''),
            'type' => (string) $item['type'], 'unit' => (string) $item['unit'],
            'price' => (new Formatter())->decimalAmount((int) $item['price_minor'], $this->app->lang()),
            'currency' => (string) $item['currency'], 'vat_bp' => (string) (int) $item['vat_bp'],
        ];
    }

    /**
     * @param array<string, mixed>|null $item the saved item shown in the form (null = new item)
     * @param array<string, string> $form
     * @param array<string, string> $errors field => translation key
     */
    private function page(Request $request, ?array $item, array $form, array $errors = [], int $status = 200, bool $saved = false): Response
    {
        $q = trim($request->queryParam('q'));
        $lang = $this->app->lang();
        $fmt = new Formatter();
        $rows = [];
        foreach ($this->repo()->list($q) as $i) {
            $rows[] = [
                'id' => (int) $i['id'],
                'name' => (string) $i['display_name'],
                'type' => (string) $i['type'],
                'unit' => (string) $i['unit'],
                'price' => $fmt->money(Money::of((int) $i['price_minor'], (string) $i['currency']), $lang),
                'vat' => intdiv((int) $i['vat_bp'], 100) . ' %',
                'active' => (int) $i['is_active'] === 1,
            ];
        }
        $vatChoices = [];
        foreach (ItemRepo::VAT_RATES as $bp) {
            $vatChoices[] = ['value' => (string) $bp, 'label' => intdiv($bp, 100) . ' %'];
        }
        return $this->app->render('items/list.twig', [
            'items' => $rows,
            'q' => $q,
            'item' => $item,
            'item_active' => $item !== null && (int) $item['is_active'] === 1,
            'form' => $form,
            'errors' => array_map(fn (string $key): string => $this->app->t($key), $errors),
            'saved' => $saved,
            'types' => ItemRepo::TYPES,
            'units' => Units::KEYS,
            'currencies' => ItemRepo::CURRENCIES,
            'vat_choices' => $vatChoices,
            'name_langs' => ItemRepo::LANGS,
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
}
