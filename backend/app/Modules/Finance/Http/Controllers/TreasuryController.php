<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\OpeningService;
use App\Modules\Finance\Application\TreasuryService;
use App\Modules\Finance\Infrastructure\Models\AccountTransfer;
use App\Modules\Finance\Infrastructure\Models\CompanyOpening;
use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialCorrection;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Transferts, dépenses, corrections, rubrique « En attente » et ouverture (docs/05 §2.8). */
final class TreasuryController
{
    private const MONEY = 'regex:/^\d{1,15}(\.\d{1,4})?$/';

    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly TenantContext $tenant,
    ) {}

    public function transfers(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);

        return Paginated::response(AccountTransfer::query()->orderByDesc('occurred_at')->orderByDesc('id')->paginate($paging['per_page'], ['*'], 'page', $paging['page']),
            fn (AccountTransfer $t) => self::transfer($t));
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from_account_id' => ['required', 'string', 'size:26'],
            'to_account_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', self::MONEY],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,10})?$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return new JsonResponse(['data' => self::transfer($this->treasury->transfer($v['from_account_id'], $v['to_account_id'], $v['amount'], $v['exchange_rate'] ?? null, $v['description'] ?? null, self::actor($request)))], 201);
    }

    public function categories(): JsonResponse
    {
        return new JsonResponse(['data' => array_values(ExpenseCategory::query()->orderBy('name')->get()->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active])->all())]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:96', Rule::unique('expense_categories', 'name')->where('company_id', $this->tenant->requireCompanyId())]]);
        $category = new ExpenseCategory;
        $category->fill(['name' => $v['name'], 'is_active' => true])->save();

        return new JsonResponse(['data' => ['id' => $category->id, 'name' => $category->name, 'is_active' => true]], 201);
    }

    public function updateCategory(Request $request, string $category): JsonResponse
    {
        $model = ExpenseCategory::query()->findOrFail($category);
        $v = $request->validate(['name' => ['sometimes', 'string', 'max:96'], 'is_active' => ['sometimes', 'boolean']]);
        $model->fill($v)->save();

        return new JsonResponse(['data' => ['id' => $model->id, 'name' => $model->name, 'is_active' => $model->is_active]]);
    }

    public function expenses(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $v = $request->validate(['filter.category_id' => ['sometimes', 'string', 'size:26'], 'filter.from' => ['sometimes', 'date_format:Y-m-d'], 'filter.to' => ['sometimes', 'date_format:Y-m-d']]);
        $f = (array) ($v['filter'] ?? []);
        $query = Expense::query()->orderByDesc('expense_date')->orderByDesc('number')
            ->when(isset($f['category_id']), fn ($q) => $q->where('category_id', $f['category_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('expense_date', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('expense_date', '<=', $f['to']));

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Expense $e) => self::expense($e));
    }

    public function storeExpense(Request $request, PrivateFileStorage $files): JsonResponse
    {
        $v = $request->validate([
            'category_id' => ['required', 'string', 'size:26'],
            'financial_account_id' => ['required', 'string', 'size:26'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', self::MONEY],
            'attachment' => ['sometimes', 'file', 'max:10240'],
        ]);
        $fileId = $request->hasFile('attachment') ? $files->storeUpload($request->file('attachment'), FilePurpose::ExpenseReceipt)->id : null;
        $expense = $this->treasury->expense($v['category_id'], $v['financial_account_id'], CarbonImmutable::parse($v['expense_date']), $v['label'], $v['amount'], $fileId, self::actor($request));

        return new JsonResponse(['data' => self::expense($expense)], 201);
    }

    public function reverseExpense(Request $request, string $expense): JsonResponse
    {
        $id = Expense::query()->findOrFail($expense)->id;
        $v = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new JsonResponse(['data' => self::expense($this->treasury->reverseExpense($id, $v['reason'], self::actor($request)))]);
    }

    public function corrections(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);

        return Paginated::response(FinancialCorrection::query()->orderByDesc('created_at')->orderByDesc('id')->paginate($paging['per_page'], ['*'], 'page', $paging['page']),
            fn (FinancialCorrection $c) => self::correction($c));
    }

    public function storeCorrection(Request $request): JsonResponse
    {
        $v = $request->validate([
            'financial_account_id' => ['required', 'string', 'size:26'],
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'string', self::MONEY],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return new JsonResponse(['data' => self::correction($this->treasury->correction($v['financial_account_id'], $v['direction'], $v['amount'], $v['reason'], self::actor($request)))], 201);
    }

    /** FIN-002 : opérations en attente d'approbation, sans mouvement financier. */
    public function pending(): JsonResponse
    {
        return new JsonResponse(['data' => [
            'corrections' => array_values(FinancialCorrection::query()->where('status', 'pending_approval')->orderBy('created_at')->get()->map(fn (FinancialCorrection $c) => self::correction($c))->all()),
        ]]);
    }

    public function opening(OpeningService $openings): JsonResponse
    {
        $opening = $openings->current();

        return new JsonResponse(['data' => $opening === null ? null : self::presentOpening($opening)]);
    }

    public function saveOpening(Request $request, OpeningService $openings): JsonResponse
    {
        $v = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'accounts' => ['sometimes', 'array'],
            'accounts.*.account_id' => ['required', 'string', 'size:26'],
            'accounts.*.amount' => ['required', 'string', self::MONEY],
            'stock' => ['sometimes', 'array', 'max:5000'],
            'stock.*.warehouse_id' => ['required', 'string', 'size:26'],
            'stock.*.variant_id' => ['required', 'string', 'size:26'],
            'stock.*.quantity' => ['required', 'integer', 'min:1'],
            'stock.*.unit_cost' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
        ]);
        /** @var list<array{account_id: string, amount: string}> $accounts */
        $accounts = array_values($v['accounts'] ?? []);
        /** @var list<array{warehouse_id: string, variant_id: string, quantity: int, unit_cost: string}> $stock */
        $stock = array_values(array_map(fn (array $l) => ['warehouse_id' => (string) $l['warehouse_id'], 'variant_id' => (string) $l['variant_id'], 'quantity' => (int) $l['quantity'], 'unit_cost' => (string) $l['unit_cost']], $v['stock'] ?? []));

        return new JsonResponse(['data' => self::presentOpening($openings->save(CarbonImmutable::parse($v['start_date']), $accounts, $stock, self::actor($request)))]);
    }

    public function postOpening(Request $request, OpeningService $openings): JsonResponse
    {
        return new JsonResponse(['data' => self::presentOpening($openings->post(self::actor($request)))]);
    }

    /** @return array<string, mixed> */
    private static function transfer(AccountTransfer $t): array
    {
        return ['id' => $t->id, 'number' => $t->number, 'from_account_id' => $t->from_account_id, 'to_account_id' => $t->to_account_id, 'amount_from' => $t->amount_from,
            'amount_to' => $t->amount_to, 'exchange_rate' => $t->exchange_rate, 'occurred_at' => $t->occurred_at->toIso8601ZuluString(), 'description' => $t->description, 'status' => $t->status];
    }

    /** @return array<string, mixed> */
    private static function expense(Expense $e): array
    {
        return ['id' => $e->id, 'number' => $e->number, 'category_id' => $e->category_id, 'financial_account_id' => $e->financial_account_id, 'expense_date' => $e->expense_date->toDateString(),
            'label' => $e->label, 'amount' => $e->amount, 'currency' => $e->currency, 'attachment_file_id' => $e->attachment_file_id, 'status' => $e->status, 'reversal_reason' => $e->reversal_reason];
    }

    /** @return array<string, mixed> */
    private static function correction(FinancialCorrection $c): array
    {
        return ['id' => $c->id, 'financial_account_id' => $c->financial_account_id, 'direction' => $c->direction, 'amount' => $c->amount, 'reason' => $c->reason,
            'status' => $c->status, 'posted_at' => $c->posted_at?->toIso8601ZuluString(), 'created_at' => $c->created_at->toIso8601ZuluString()];
    }

    /** @return array<string, mixed> */
    private static function presentOpening(CompanyOpening $o): array
    {
        return ['id' => $o->id, 'start_date' => $o->start_date->toDateString(), 'status' => $o->status, 'accounts' => $o->accounts, 'stock' => $o->stock,
            'posted_at' => $o->posted_at?->toIso8601ZuluString(), 'summary' => $o->summary];
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
