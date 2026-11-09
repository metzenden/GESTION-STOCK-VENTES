<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\IdentityAccess\Contracts\Invitations;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use App\Modules\Tenancy\Application\CompanyLifecycle;
use App\Modules\Tenancy\Application\CompanyProvisioner;
use App\Modules\Tenancy\Http\Resources\CompanyResource;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Administration des entreprises par le super-administrateur (docs/05 §2.1). */
final class AdminCompanyController
{
    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'filter.status' => ['sometimes', Rule::in(['provisioning', 'active', 'suspended', 'archived'])],
        ]);

        $query = Company::query()->orderBy('trade_name');

        if (isset($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('trade_name', 'like', $term)->orWhere('code', 'like', $term));
        }

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        $page = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);

        return Paginated::response($page, fn (Company $company) => (new CompanyResource($company))->resolve($request));
    }

    public function store(Request $request, CompanyProvisioner $provisioner): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('companies', 'code')],
            'reference_currency' => ['required', 'string', Rule::exists('currencies', 'code')],
            'admin_email' => ['required', 'email:rfc', 'max:190'],
            ...$this->identityRules(required: true),
        ]);

        $company = $provisioner->create($validated, $validated['code'], $validated['reference_currency'], $validated['admin_email'], $request->user()?->getAuthIdentifier());

        return (new CompanyResource($company))->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/admin/companies/{$company->id}"));
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
    }

    public function update(Request $request, Company $company): CompanyResource
    {
        $validated = $request->validate($this->identityRules(required: false));
        $company->fill($validated)->forceFill(['updated_by' => $request->user()?->getAuthIdentifier()])->save();

        return new CompanyResource($company);
    }

    public function suspend(Request $request, Company $company, CompanyLifecycle $lifecycle): CompanyResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new CompanyResource($lifecycle->suspend($company->id, $validated['reason'], $request->user()?->getAuthIdentifier()));
    }

    public function reactivate(Request $request, Company $company, CompanyLifecycle $lifecycle): CompanyResource
    {
        return new CompanyResource($lifecycle->reactivate($company->id, $request->user()?->getAuthIdentifier()));
    }

    public function invite(Request $request, Company $company, Invitations $invitations, TenantContext $tenant, ConnectionInterface $db): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        $db->transaction(fn () => $tenant->runAs($company->id, fn () => $invitations->inviteCompanyAdmin($company->id, $validated['email'])));

        return new JsonResponse(null, 204);
    }

    /** @return array<string, list<mixed>> */
    private function identityRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'trade_name' => [$presence, 'string', 'min:2', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'ninea' => ['sometimes', 'nullable', 'string', 'max:32'],
            'rccm' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha:ascii'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
        ];
    }
}
