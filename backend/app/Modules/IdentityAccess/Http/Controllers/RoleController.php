<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Modules\IdentityAccess\Application\RoleAdministration;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Http\Resources\RoleResource;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rôles de l'entreprise active et catalogue des permissions (docs/05 §2.2). */
final class RoleController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()->where('company_id', $this->tenant->requireCompanyId())
            ->with('permissions')->orderByDesc('is_system')->orderBy('name')->get();

        return new JsonResponse(['data' => $roles->map(fn (Role $role) => (new RoleResource($role))->resolve($request))->values()]);
    }

    public function show(string $role): RoleResource
    {
        return new RoleResource($this->find($role));
    }

    public function store(Request $request, RoleAdministration $roles): JsonResponse
    {
        $validated = $request->validate($this->rules(required: true));
        $role = $roles->create($this->tenant->requireCompanyId(), $validated['name'], array_values($validated['permissions']), $this->actor($request));

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $role, RoleAdministration $roles): RoleResource
    {
        $validated = $request->validate($this->rules(required: false));
        $updated = $roles->update(
            $this->tenant->requireCompanyId(),
            $this->find($role)->id,
            $validated['name'] ?? null,
            isset($validated['permissions']) ? array_values($validated['permissions']) : null,
            $this->actor($request),
        );

        return new RoleResource($updated->load('permissions'));
    }

    public function destroy(string $role, RoleAdministration $roles): JsonResponse
    {
        $roles->delete($this->tenant->requireCompanyId(), $this->find($role)->id);

        return new JsonResponse(null, 204);
    }

    public function permissions(): JsonResponse
    {
        $data = [];

        foreach (PermissionCatalog::definitions() as $name => $definition) {
            if (! PermissionCatalog::isPlatform($name)) {
                $data[] = ['name' => $name, 'module' => $definition['module'], 'label' => $definition['label'], 'is_sensitive' => $definition['sensitive'] ?? false];
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    /** @return array<string, list<string>> */
    private function rules(bool $required): array
    {
        return [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'min:2', 'max:64'],
            'permissions' => [$required ? 'present' : 'sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', 'distinct', 'max:96'],
        ];
    }

    private function find(string $id): Role
    {
        return Role::query()->where('company_id', $this->tenant->requireCompanyId())->with('permissions')->findOrFail($id);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
