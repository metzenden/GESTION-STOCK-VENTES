<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Modules\IdentityAccess\Application\InvitationService;
use App\Modules\IdentityAccess\Application\UserAdministration;
use App\Modules\IdentityAccess\Http\Resources\InvitationResource;
use App\Modules\IdentityAccess\Http\Resources\UserResource;
use App\Modules\IdentityAccess\Infrastructure\Models\Invitation;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Utilisateurs et invitations de l'entreprise active (docs/05 §2.2). */
final class UserController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'filter.status' => ['sometimes', Rule::in(['active', 'disabled', 'invited'])],
        ]);

        $query = User::query()->where('company_id', $this->tenant->requireCompanyId())->with('roles')->orderBy('name');

        if (isset($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        return Paginated::response(
            $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']),
            fn (User $user) => (new UserResource($user))->resolve($request),
        );
    }

    public function show(string $user): UserResource
    {
        return new UserResource($this->find($user));
    }

    public function update(Request $request, string $user, UserAdministration $users): UserResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role_ids' => ['sometimes', 'array', 'min:1', 'max:10'],
            'role_ids.*' => ['string', 'size:26'],
        ]);

        return new UserResource($users->update($this->tenant->requireCompanyId(), $this->find($user)->id, $validated, $this->actor($request)));
    }

    public function disable(Request $request, string $user, UserAdministration $users): UserResource
    {
        return new UserResource($users->disable($this->tenant->requireCompanyId(), $this->find($user)->id, $this->actor($request)));
    }

    public function enable(Request $request, string $user, UserAdministration $users): UserResource
    {
        return new UserResource($users->enable($this->tenant->requireCompanyId(), $this->find($user)->id, $this->actor($request)));
    }

    public function invitations(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $query = Invitation::query()->where('company_id', $this->tenant->requireCompanyId())
            ->with('role')->orderByDesc('created_at');

        return Paginated::response(
            $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']),
            fn (Invitation $invitation) => (new InvitationResource($invitation))->resolve($request),
        );
    }

    public function invite(Request $request, InvitationService $invitations): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role_id' => ['required', 'string', 'size:26'],
        ]);

        $invitation = $invitations->invite($this->tenant->requireCompanyId(), $validated['email'], $validated['role_id'], $this->actor($request));

        return (new InvitationResource($invitation->load('role')))->response()->setStatusCode(201);
    }

    public function revokeInvitation(string $invitation, InvitationService $invitations): InvitationResource
    {
        return new InvitationResource($invitations->revoke($this->tenant->requireCompanyId(), $invitation)->load('role'));
    }

    private function find(string $id): User
    {
        return User::query()->where('company_id', $this->tenant->requireCompanyId())->with('roles')->findOrFail($id);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
