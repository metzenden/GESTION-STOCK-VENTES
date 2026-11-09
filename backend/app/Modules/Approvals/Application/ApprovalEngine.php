<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Application;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Approvals\Infrastructure\Mail\ApprovalRequestedMail;
use App\Modules\Approvals\Infrastructure\Models\ApprovalRequest;
use App\Modules\Approvals\Infrastructure\Models\ApprovalRule;
use App\Modules\Approvals\Infrastructure\Models\InboxNotification;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Contracts\UserDirectory;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Moteur d'approbation (L6-01, docs/04 §12) : règle applicable = règle active de plus haute priorité
 * correspondant à (opération, rôle du demandeur, seuil) (APR-002). L'approbation exécute l'opération et lève
 * les effets provisoires dans une transaction ; un échec d'exécution laisse la demande en attente (APR-007).
 */
final class ApprovalEngine implements ApprovalGate
{
    /** Opération appelante → opération de règle (APR-001) et mesure comparée au seuil. */
    private const MAP = [
        'sales_order' => [['discount', 'discount_percent'], ['price_override', 'price_overridden']],
        'sales_order_cancellation' => [['cancellation', 'total']],
        'stock_adjustment' => [['stock_adjustment', 'quantity']],
        'credit_note' => [['credit_note', 'amount']],
        'financial_correction' => [['financial_correction', 'amount']],
        'payment_reversal' => [['financial_correction', 'amount']],
        'payment_method_change' => [['financial_correction', 'amount']],
        'expense_reversal' => [['financial_correction', 'amount']],
    ];

    /** @var array<string, ApprovalRule> dernière règle retenue par opération appelante */
    private array $matched = [];

    private ?string $lastRequest = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
        private readonly UserDirectory $users,
        private readonly CompanyPreferences $preferences,
        private readonly DocumentNumberAllocator $numbers,
        private readonly Container $container,
        private readonly AuditRecorder $audit,
    ) {}

    public function requiresApproval(string $operation, array $context = []): bool
    {
        if ($this->tenant->companyId() === null || ! isset(self::MAP[$operation])) {
            return false;
        }

        $actor = $this->actor();
        $roles = $actor !== null ? $this->users->roleIds($actor) : [];

        foreach (self::MAP[$operation] as [$ruleOperation, $metric]) {
            $value = self::metric($metric, $context);
            $rules = ApprovalRule::query()->where('operation', $ruleOperation)->where('is_active', true)->orderByDesc('priority')->orderBy('id')->get();

            foreach ($rules as $rule) {
                if ($rule->role_id !== null && ! in_array($rule->role_id, $roles, true)) {
                    continue;
                }

                if (self::exceeds($rule, $value)) {
                    $this->matched[$operation] = $rule;

                    return true;
                }
            }
        }

        unset($this->matched[$operation]);

        return false;
    }

    public function submit(string $operation, string $subjectType, string $subjectId, array $context, string $summary): string
    {
        $existing = ApprovalRequest::query()->where('subject_id', $subjectId)->where('operation', $operation)->where('status', 'pending')->first();

        if ($existing !== null) {
            return $this->lastRequest = $existing->id;
        }

        $rule = $this->matched[$operation] ?? null;
        $request = new ApprovalRequest;
        $request->fill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::ApprovalRequest, CarbonImmutable::now()),
            'operation' => $operation,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'rule_id' => $rule?->id,
            'approver_permission' => $rule->approver_permission ?? 'approvals.approve',
            'summary' => mb_substr($summary, 0, 255),
            'context' => $context,
            'status' => 'pending',
            'requested_by' => $this->actor(),
        ])->forceFill(['created_by' => $this->actor()])->save();
        $this->audit->record('approval_request.created', 'approval_request', $request->id, [], ['number' => $request->number, 'operation' => $operation, 'subject_id' => $subjectId]);
        $this->notifyApprovers($request);

        return $this->lastRequest = $request->id;
    }

    public function lastRequestId(): ?string
    {
        return $this->lastRequest;
    }

    public function reset(): void
    {
        $this->lastRequest = null;
        $this->matched = [];
    }

    /** APR-003, APR-005, APR-007 : décision de l'approbateur, exécution atomique et idempotente. */
    public function decide(string $requestId, bool $approve, ?string $comment, string $actorId): ApprovalRequest
    {
        return $this->db->transaction(function () use ($requestId, $approve, $comment, $actorId) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw BusinessRuleViolation::alreadyProcessed('Cette demande a déjà été traitée.');
            }

            if (! $this->users->hasPermission($actorId, $request->approver_permission)) {
                throw new BusinessRuleViolation('forbidden', 'Permission insuffisante', 'Vous n\'avez pas la permission d\'approuver cette demande.', 403);
            }

            $self = $request->requested_by === $actorId;

            if ($self && ! $this->preferences->allowsSelfApproval()) {
                throw new BusinessRuleViolation('self_approval_forbidden', 'Auto-approbation interdite', 'Une autre personne doit approuver votre demande.', 403);
            }

            $handler = $this->handler($request->operation);

            try {
                $this->db->transaction(fn () => $approve
                    ? $handler->approve($request->operation, $request->subject_id, $request->context, $actorId)
                    : $handler->reject($request->operation, $request->subject_id, $request->context, $actorId));
            } catch (BusinessRuleViolation $e) {
                // APR-007 : rien n'est écrit, la demande reste en attente avec le message.
                $request->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }

            $request->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $actorId,
                'decided_at' => CarbonImmutable::now(),
                'comment' => $comment,
                'self_approved' => $approve && $self,
                'last_error' => null,
                'updated_by' => $actorId,
            ])->save();
            $this->audit->record('approval_request.'.($approve ? 'approved' : 'rejected'), 'approval_request', $request->id, ['status' => 'pending'],
                ['status' => $request->status, 'self_approved' => $request->self_approved, 'comment' => $comment]);

            if ($request->requested_by !== null && $request->requested_by !== $actorId) {
                $this->notify($request->requested_by, 'approval_decided', ($approve ? 'Demande approuvée : ' : 'Demande rejetée : ').$request->number, $request->summary, "/approbations/{$request->id}");
            }

            return $request->refresh();
        }, 3);
    }

    /** APR-006 : retrait par le demandeur, levée des effets provisoires. */
    public function withdraw(string $requestId, string $actorId): ApprovalRequest
    {
        return $this->db->transaction(function () use ($requestId, $actorId) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw BusinessRuleViolation::alreadyProcessed('Cette demande a déjà été traitée.');
            }

            if ($request->requested_by !== $actorId) {
                throw new BusinessRuleViolation('forbidden', 'Permission insuffisante', 'Seul le demandeur peut retirer sa demande.', 403);
            }

            $this->handler($request->operation)->reject($request->operation, $request->subject_id, $request->context, $actorId);
            $request->forceFill(['status' => 'cancelled', 'decided_by' => $actorId, 'decided_at' => CarbonImmutable::now(), 'updated_by' => $actorId])->save();
            $this->audit->record('approval_request.cancelled', 'approval_request', $request->id, ['status' => 'pending'], ['status' => 'cancelled']);

            return $request->refresh();
        }, 3);
    }

    public function notify(string $userId, string $type, string $title, ?string $body, ?string $link): void
    {
        (new InboxNotification)->fill(['user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 160), 'body' => $body === null ? null : mb_substr($body, 0, 500), 'link' => $link])->save();
    }

    private function notifyApprovers(ApprovalRequest $request): void
    {
        $requester = $request->requested_by !== null ? ($this->users->find($request->requested_by)['name'] ?? '') : '';
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/')."/approbations/{$request->id}";

        foreach ($this->users->withPermission($request->approver_permission) as $user) {
            $this->notify($user['id'], 'approval_requested', "Approbation demandée : {$request->number}", $request->summary, "/approbations/{$request->id}");
            Mail::to($user['email'])->queue(new ApprovalRequestedMail($request->number, $request->summary, $requester, $url));
        }
    }

    private function handler(string $operation): ApprovableOperation
    {
        /** @var iterable<ApprovableOperation> $handlers */
        $handlers = $this->container->tagged(ApprovableOperation::TAG);

        foreach ($handlers as $handler) {
            if (in_array($operation, $handler->operations(), true)) {
                return $handler;
            }
        }

        throw new \LogicException("Aucun module ne prend en charge l'opération {$operation}.");
    }

    /** @param array<string, mixed> $context */
    private static function metric(string $metric, array $context): BigDecimal
    {
        return match ($metric) {
            'discount_percent' => isset($context['subtotal']) && Decimal::of((string) $context['subtotal'])->isPositive()
                ? Decimal::of((string) ($context['discount_total'] ?? '0'))->multipliedBy(100)->dividedBy(Decimal::of((string) $context['subtotal']), 4, RoundingMode::HalfUp)
                : BigDecimal::zero(),
            'price_overridden' => ($context['price_overridden'] ?? false) === true ? BigDecimal::one() : BigDecimal::zero(),
            'quantity' => BigDecimal::of((int) ($context['total_out'] ?? 0) + (int) ($context['total_in'] ?? 0)),
            default => Decimal::of((string) ($context[$metric] ?? $context['amount'] ?? '0'))->abs(),
        };
    }

    private static function exceeds(ApprovalRule $rule, BigDecimal $value): bool
    {
        if ($rule->operation === 'price_override') {
            return $value->isPositive();
        }

        return $rule->threshold_type === 'always' || $value->isGreaterThan(Decimal::of((string) $rule->threshold_value));
    }

    private function actor(): ?string
    {
        $id = $this->auth->guard()->id();

        return is_string($id) ? $id : null;
    }
}
