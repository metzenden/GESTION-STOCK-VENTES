<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Infrastructure\Models\FinancialAccount;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Comptes financiers (docs/05 §2.8) : soldes visibles avec financial_accounts.view_sensitive (GEN-005). */
final class FinancialAccountController
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    public function index(): JsonResponse
    {
        $sensitive = Gate::allows('financial_accounts.view_sensitive');
        $accounts = FinancialAccount::query()->orderBy('code')->get()->map(fn (FinancialAccount $a) => self::present($a, $sensitive))->values()->all();

        return new JsonResponse(['data' => $accounts, 'meta' => ['redacted' => $sensitive ? [] : ['balance', 'allow_negative']]]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('financial_accounts', 'code')->where('company_id', $this->tenant->requireCompanyId())],
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', 'in:cash,wave,orange_money,bank,other'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'allow_negative' => ['sometimes', 'boolean'],
        ]);
        $account = new FinancialAccount;
        $account->fill($validated + ['status' => 'active'])->forceFill(['created_by' => self::actor($request)])->save();

        return new JsonResponse(['data' => self::present($account->refresh(), true)], 201);
    }

    public function update(Request $request, string $account): JsonResponse
    {
        $model = FinancialAccount::query()->findOrFail($account);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'allow_negative' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (($validated['allow_negative'] ?? true) === false && str_starts_with((string) $model->balance, '-')) {
            throw BusinessRuleViolation::invalidField('allow_negative', 'Le solde est négatif : régularisez-le avant d\'interdire le découvert.');
        }

        $model->fill($validated)->forceFill(['updated_by' => self::actor($request)])->save();

        return new JsonResponse(['data' => self::present($model->refresh(), true)]);
    }

    public function movements(Request $request, string $account): JsonResponse
    {
        $model = FinancialAccount::query()->findOrFail($account);
        $paging = Paginated::input($request);
        $page = $this->db->table('financial_movements')->where('company_id', $this->tenant->requireCompanyId())->where('financial_account_id', $model->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate($paging['per_page'], ['*'], 'page', $paging['page']);

        return Paginated::response($page, fn (\stdClass $m) => [
            'id' => $m->id,
            'movement_type' => $m->movement_type,
            'direction' => $m->direction,
            'amount' => (string) $m->amount,
            'currency' => $m->currency,
            'balance_after' => (string) $m->balance_after,
            'description' => $m->description,
            'source_type' => $m->source_type,
            'source_id' => $m->source_id,
            'reverses_movement_id' => $m->reverses_movement_id,
            'occurred_at' => CarbonImmutable::parse((string) $m->occurred_at, 'UTC')->toIso8601ZuluString(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function present(FinancialAccount $a, bool $sensitive): array
    {
        return array_filter([
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'account_type' => $a->account_type,
            'currency' => $a->currency,
            'status' => $a->status,
            'allow_negative' => $sensitive ? $a->allow_negative : '__redacted__',
            'balance' => $sensitive ? (string) $a->balance : '__redacted__',
        ], fn ($v) => $v !== '__redacted__');
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
