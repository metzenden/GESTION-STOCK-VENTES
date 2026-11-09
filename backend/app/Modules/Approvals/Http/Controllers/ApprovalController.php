<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers;

use App\Modules\Approvals\Application\ApprovalEngine;
use App\Modules\Approvals\Infrastructure\Models\ApprovalRequest;
use App\Modules\Approvals\Infrastructure\Models\ApprovalRule;
use App\Modules\Approvals\Infrastructure\Models\InboxNotification;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Règles et demandes d'approbation, notifications in-app (docs/05 §2.9). */
final class ApprovalController
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function rules(): JsonResponse
    {
        return new JsonResponse(['data' => array_values(ApprovalRule::query()->orderBy('operation')->orderByDesc('priority')->get()->map(fn (ApprovalRule $r) => self::rule($r))->all())]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $rule = new ApprovalRule;
        $rule->fill($request->validate(self::ruleRules(true)))->save();

        return new JsonResponse(['data' => self::rule($rule->refresh())], 201);
    }

    public function updateRule(Request $request, string $rule): JsonResponse
    {
        $model = ApprovalRule::query()->findOrFail($rule);
        $model->fill($request->validate(self::ruleRules(false)))->save();

        return new JsonResponse(['data' => self::rule($model->refresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $v = $request->validate(['filter.status' => ['sometimes', 'in:pending,approved,rejected,cancelled'], 'filter.mine' => ['sometimes', 'boolean']]);
        $query = ApprovalRequest::query()->orderByDesc('created_at')->orderByDesc('id');
        $actor = (string) $request->user()?->getAuthIdentifier();

        // Un demandeur voit ses demandes ; un approbateur voit toutes celles de l'entreprise.
        if (! Gate::allows('approvals.approve') || (bool) ($v['filter']['mine'] ?? false)) {
            $query->where('requested_by', $actor);
        }

        if (isset($v['filter']['status'])) {
            $query->where('status', $v['filter']['status']);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (ApprovalRequest $r) => self::request($r));
    }

    public function show(Request $request, string $approval): JsonResponse
    {
        $model = ApprovalRequest::query()->findOrFail($approval);
        abort_unless(Gate::allows('approvals.approve') || $model->requested_by === $request->user()?->getAuthIdentifier(), 404);

        return new JsonResponse(['data' => self::request($model)]);
    }

    public function approve(Request $request, string $approval): JsonResponse
    {
        return $this->decide($request, $approval, true);
    }

    public function reject(Request $request, string $approval): JsonResponse
    {
        return $this->decide($request, $approval, false);
    }

    public function withdraw(Request $request, string $approval): JsonResponse
    {
        $id = ApprovalRequest::query()->findOrFail($approval)->id;

        return new JsonResponse(['data' => self::request($this->engine->withdraw($id, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $query = InboxNotification::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->orderByDesc('created_at')->orderByDesc('id');
        $unread = (clone $query)->whereNull('read_at')->count();
        $response = Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (InboxNotification $n) => [
            'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
            'read_at' => $n->read_at?->toIso8601ZuluString(), 'created_at' => $n->created_at->toIso8601ZuluString(),
        ]);
        /** @var array{data: list<mixed>, meta: array<string, mixed>} $payload */
        $payload = $response->getData(true);
        $payload['meta']['unread'] = $unread;

        return $response->setData($payload);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $model = InboxNotification::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->findOrFail($notification);
        $model->forceFill(['read_at' => $model->read_at ?? now()])->save();

        return new JsonResponse(['data' => ['id' => $model->id, 'read_at' => $model->read_at?->toIso8601ZuluString()]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = InboxNotification::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->whereNull('read_at')->update(['read_at' => now()]);

        return new JsonResponse(['data' => ['updated' => $count]]);
    }

    private function decide(Request $request, string $approval, bool $approve): JsonResponse
    {
        $id = ApprovalRequest::query()->findOrFail($approval)->id;
        $v = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return new JsonResponse(['data' => self::request($this->engine->decide($id, $approve, $v['comment'] ?? null, (string) $request->user()?->getAuthIdentifier()))]);
    }

    /** @return array<string, list<string>> */
    private static function ruleRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:120'],
            'operation' => [$r, 'in:discount,price_override,cancellation,stock_adjustment,credit_note,financial_correction'],
            'role_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'threshold_type' => ['sometimes', 'in:always,amount,percent,quantity'],
            'threshold_value' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'approver_permission' => ['sometimes', 'string', 'max:64'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private static function rule(ApprovalRule $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'operation' => $r->operation, 'role_id' => $r->role_id, 'threshold_type' => $r->threshold_type,
            'threshold_value' => $r->threshold_value, 'approver_permission' => $r->approver_permission, 'priority' => $r->priority, 'is_active' => $r->is_active];
    }

    /** @return array<string, mixed> */
    private static function request(ApprovalRequest $r): array
    {
        return ['id' => $r->id, 'number' => $r->number, 'operation' => $r->operation, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
            'summary' => $r->summary, 'status' => $r->status, 'requested_by' => $r->requested_by, 'decided_by' => $r->decided_by,
            'decided_at' => $r->decided_at?->toIso8601ZuluString(), 'comment' => $r->comment, 'self_approved' => $r->self_approved,
            'last_error' => $r->last_error, 'created_at' => $r->created_at->toIso8601ZuluString()];
    }
}
