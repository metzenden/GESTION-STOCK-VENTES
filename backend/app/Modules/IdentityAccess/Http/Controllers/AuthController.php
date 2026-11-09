<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Modules\IdentityAccess\Application\Authenticator;
use App\Modules\IdentityAccess\Application\CurrentUserProfile;
use App\Modules\IdentityAccess\Application\InvitationService;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Authentification par session Sanctum (docs/05 §2.1). */
final class AuthController
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly CurrentUserProfile $profile,
        private readonly TenantContext $tenant,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->authenticator->login($request, $validated['email'], $validated['password']);
        $this->tenant->set($user->company_id);

        return new JsonResponse(['data' => $this->profile->for($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authenticator->logout($request);

        return new JsonResponse(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $this->profile->for($user)]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $validated = $request->validate(['theme' => ['required', Rule::in(['system', 'light', 'dark'])]]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['theme' => $validated['theme']])->saveQuietly();

        return new JsonResponse(['data' => $this->profile->for($user)]);
    }

    public function acceptInvitation(Request $request, InvitationService $invitations): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $invitations->accept($validated['token'], $validated['name'], $validated['password']);
        $this->authenticator->startSession($request, $user);
        $this->tenant->set($user->company_id);

        return new JsonResponse(['data' => $this->profile->for($user)], 201);
    }
}
