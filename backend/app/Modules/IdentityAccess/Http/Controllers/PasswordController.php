<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Mot de passe oublié (L1-06) : réponse identique que l'adresse existe ou non ;
 * jeton à usage unique, 60 min ; la réinitialisation ferme toutes les sessions ouvertes.
 */
final class PasswordController
{
    private const NEUTRAL_MESSAGE = 'Si un compte actif correspond à cette adresse, un e-mail de réinitialisation vient d\'être envoyé.';

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:190']]);
        $email = Str::lower(trim($validated['email']));

        if (User::query()->where('email', $email)->where('status', 'active')->exists()) {
            PasswordBroker::broker()->sendResetLink(['email' => $email]);
        }

        return new JsonResponse(['data' => ['message' => self::NEUTRAL_MESSAGE]], 202);
    }

    public function reset(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::broker()->reset(
            ['email' => Str::lower(trim($validated['email'])), 'token' => $validated['token'], 'password' => $validated['password']],
            function (User $user, string $password) use ($audit) {
                $user->forceFill([
                    'password' => $password,
                    'session_epoch' => $user->session_epoch + 1,
                    'remember_token' => null,
                ])->saveQuietly();

                $audit->record('auth.password_reset', 'user', $user->id, companyId: $user->company_id, userId: $user->id);
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw new BusinessRuleViolation(
                'invalid_reset_token',
                'Lien invalide',
                'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.',
                422,
            );
        }

        return new JsonResponse(['data' => ['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']]);
    }
}
