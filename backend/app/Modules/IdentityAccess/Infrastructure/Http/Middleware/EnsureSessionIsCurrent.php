<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Http\Middleware;

use App\Modules\IdentityAccess\Application\Authenticator;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invalide immédiatement une session dont le compte a été désactivé ou dont le mot de passe
 * a changé depuis l'ouverture (ADM-003, L1-13) : l'époque de session ne correspond plus.
 */
final class EnsureSessionIsCurrent
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $epoch = $request->hasSession() ? $request->session()->get(Authenticator::SESSION_EPOCH_KEY) : null;

            if (! $user->isActive() || $epoch !== $user->session_epoch) {
                $this->auth->guard('web')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                }

                throw new BusinessRuleViolation('session_expired', 'Session expirée', 'Votre session a expiré. Reconnectez-vous.', 401);
            }
        }

        return $next($request);
    }
}
