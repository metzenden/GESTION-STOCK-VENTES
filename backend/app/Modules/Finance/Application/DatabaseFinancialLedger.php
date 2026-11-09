<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Contracts\AccountSummary;
use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\Finance\Infrastructure\Models\FinancialAccount;
use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseFinancialLedger implements FinancialLedger
{
    private const TYPES = ['customer_receipt', 'supplier_payment', 'expense', 'transfer_in', 'transfer_out', 'opening', 'correction', 'landed_cost_payment', 'other_in', 'other_out'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
    ) {}

    public function post(string $accountId, string $movementType, string $direction, string $amount, string $sourceType, string $sourceId, string $description, DateTimeInterface $occurredAt): string
    {
        if (! in_array($movementType, self::TYPES, true) || ! in_array($direction, ['in', 'out'], true)) {
            throw new LogicException("Mouvement financier invalide : {$movementType}/{$direction}.");
        }

        return $this->write($accountId, $movementType, $direction, $amount, $sourceType, $sourceId, $description, $occurredAt, 0, null);
    }

    public function reverse(string $movementId, string $reason, DateTimeInterface $occurredAt): string
    {
        $this->assertTransaction();
        $original = $this->db->table('financial_movements')->where('company_id', $this->tenant->requireCompanyId())->where('id', $movementId)->first()
            ?? throw BusinessRuleViolation::invalidField('movement_id', 'Ce mouvement n\'existe pas.');

        if ($original->movement_type === 'reversal') {
            throw BusinessRuleViolation::invalidField('movement_id', 'Une contre-écriture ne se contre-passe pas.');
        }

        if ($this->db->table('financial_movements')->where('company_id', $original->company_id)->where('reverses_movement_id', $original->id)->exists()) {
            throw BusinessRuleViolation::alreadyProcessed('Ce mouvement a déjà été contre-passé.');
        }

        return $this->write((string) $original->financial_account_id, 'reversal', $original->direction === 'in' ? 'out' : 'in', (string) $original->amount,
            (string) $original->source_type, (string) $original->source_id, mb_substr("Contre-passation : {$reason}", 0, 255), $occurredAt, 1, (string) $original->id);
    }

    public function account(string $accountId): ?AccountSummary
    {
        $a = FinancialAccount::query()->find($accountId);

        return $a === null ? null : new AccountSummary($a->id, $a->code, $a->name, $a->account_type, $a->currency, $a->status);
    }

    private function write(string $accountId, string $type, string $direction, string $amount, string $sourceType, string $sourceId, string $description,
        DateTimeInterface $occurredAt, int $seq, ?string $reverses): string
    {
        $this->assertTransaction();
        $companyId = $this->tenant->requireCompanyId();
        $value = Decimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (! $value->isPositive()) {
            throw BusinessRuleViolation::invalidField('amount', 'Le montant doit être positif.');
        }

        $account = $this->db->table('financial_accounts')->where('company_id', $companyId)->where('id', $accountId)->lockForUpdate()->first()
            ?? throw BusinessRuleViolation::invalidField('financial_account_id', 'Ce compte n\'existe pas.');

        if ($account->status !== 'active' && $seq === 0) {
            throw BusinessRuleViolation::invalidField('financial_account_id', 'Ce compte est inactif.');
        }

        $balance = Decimal::of((string) $account->balance)->toScale(4);
        $after = $direction === 'in' ? $balance->plus($value) : $balance->minus($value);

        // FIN-003 : contrôle sous verrou du compte.
        if ($after->isNegative() && ! (bool) $account->allow_negative) {
            throw new BusinessRuleViolation('insufficient_funds', 'Solde insuffisant',
                "Le compte {$account->name} n'a pas un solde suffisant (solde {$balance} {$account->currency}).", 409, ['balance' => (string) $balance]);
        }

        $id = (string) Str::ulid();
        $this->db->table('financial_movements')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'financial_account_id' => $account->id,
            'movement_type' => $type,
            'direction' => $direction,
            'amount' => (string) $value,
            'currency' => $account->currency,
            'occurred_at' => CarbonImmutable::instance($occurredAt)->utc()->format('Y-m-d H:i:s.u'),
            'description' => mb_substr($description, 0, 255),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reversal_seq' => $seq,
            'reverses_movement_id' => $reverses,
            'balance_after' => (string) $after,
            'created_by' => $this->actor(),
        ]);
        $this->db->table('financial_accounts')->where('company_id', $companyId)->where('id', $account->id)->update(['balance' => (string) $after]);

        return $id;
    }

    private function actor(): string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof CompanyMember) {
            throw new LogicException('Opération financière sans utilisateur identifié.');
        }

        return $user->memberId();
    }

    private function assertTransaction(): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Les écritures financières doivent être faites dans une transaction.');
        }
    }
}
