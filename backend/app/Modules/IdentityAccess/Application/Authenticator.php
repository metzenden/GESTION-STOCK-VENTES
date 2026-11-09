<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Infrastructure\Mail\SuperAdminLoginAlert;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Connexion et ouverture de session (L1-04, L1-05, docs/02 §7).
 *
 * - 5 essais / 15 min par (e-mail, IP), 20 / 15 min par IP, 3 / 15 min par compte super-admin.
 * - Même réponse pour un e-mail inconnu, un mot de passe faux ou un compte inactif (pas d'énumération).
 * - Session régénérée ; son « époque » est mémorisée pour pouvoir la révoquer (ADM-003, D-069).
 */
final class Authenticator
{
    public const SESSION_EPOCH_KEY = 'auth_epoch';

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Hasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly AuditRecorder $audit,
    ) {}

    public function login(Request $request, string $email, string $password): User
    {
        $email = Str::lower(trim($email));
        $ip = (string) $request->ip();
        $user = User::query()->where('email', $email)->first();

        $keys = [
            'login:'.sha1($email.'|'.$ip) => 5,
            'login-ip:'.$ip => 20,
        ];

        if ($user?->is_super_admin === true) {
            $keys['login-super-admin:'.$user->id] = 3;
        }

        foreach ($keys as $key => $max) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException(
                    'Trop de tentatives de connexion.',
                    null,
                    ['Retry-After' => (string) $this->limiter->availableIn($key)],
                );
            }
        }

        if ($user === null || ! $user->isActive() || ! $this->hasher->check($password, $user->password)) {
            foreach (array_keys($keys) as $key) {
                $this->limiter->hit($key, self::DECAY_SECONDS);
            }

            $this->audit->record('auth.login_failed', 'user', $user?->id, [], ['email' => $email], companyId: $user?->company_id, userId: $user?->id);

            throw new BusinessRuleViolation(
                'invalid_credentials',
                'Identifiants incorrects',
                'Adresse e-mail ou mot de passe incorrect.',
                422,
            );
        }

        $this->limiter->clear(array_key_first($keys));

        if ($this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $password]);
        }

        $user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();
        $this->startSession($request, $user);

        $this->audit->record('auth.login', 'user', $user->id, companyId: $user->company_id, userId: $user->id);

        if ($user->is_super_admin) {
            Mail::to($user->email)->queue(new SuperAdminLoginAlert(
                $user->name,
                $ip,
                Str::limit((string) $request->userAgent(), 200),
                Carbon::now()->format('d/m/Y H:i'),
            ));
        }

        return $user;
    }

    /** Ouvre une session neuve pour l'utilisateur (connexion, acceptation d'invitation). */
    public function startSession(Request $request, User $user): void
    {
        $this->auth->guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_EPOCH_KEY, $user->session_epoch);
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record('auth.logout', 'user', $user->id, companyId: $user->company_id, userId: $user->id);
        }

        $this->auth->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
