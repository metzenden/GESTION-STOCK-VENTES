<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Consultation du journal d'audit : entreprise active (permission audit.view) ou plateforme (super-admin).
 */
final class AuditLogController
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function company(Request $request, TenantContext $tenant): JsonResponse
    {
        $query = $this->db->table('audit_logs')->where('company_id', $tenant->requireCompanyId());

        return $this->paginate($request, $query);
    }

    public function platform(Request $request): JsonResponse
    {
        $query = $this->db->table('audit_logs');

        if ($request->filled('filter.company_id')) {
            $query->where('company_id', (string) $request->input('filter.company_id'));
        }

        return $this->paginate($request, $query);
    }

    private function paginate(Request $request, Builder $query): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.action' => ['sometimes', 'string', 'max:96'],
            'filter.user_id' => ['sometimes', 'string', 'size:26'],
            'filter.subject_id' => ['sometimes', 'string', 'size:26'],
            'filter.company_id' => ['sometimes', 'string', 'size:26'],
        ]);

        $filters = $validated['filter'] ?? [];

        foreach (['action', 'user_id', 'subject_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($paging['per_page'], ['*'], 'page', $paging['page']);

        return Paginated::response($page, fn (\stdClass $row) => [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'user_id' => $row->user_id,
            'impersonated_company_id' => $row->impersonated_company_id,
            'action' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'old_values' => $row->old_values !== null ? json_decode((string) $row->old_values, true) : null,
            'new_values' => $row->new_values !== null ? json_decode((string) $row->new_values, true) : null,
            'reason' => $row->reason,
            'ip_address' => $row->ip_address,
            'request_id' => $row->request_id,
            'occurred_at' => Carbon::parse((string) $row->occurred_at)->toIso8601ZuluString('microsecond'),
        ]);
    }
}
