<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Application\EloquentUserDirectory;
use App\Modules\IdentityAccess\Application\InvitationService;
use App\Modules\IdentityAccess\Application\ProvisionCompanyRoles;
use App\Modules\IdentityAccess\Contracts\Invitations;
use App\Modules\IdentityAccess\Contracts\UserDirectory;
use App\Modules\IdentityAccess\Infrastructure\Console\CreateSuperAdminCommand;
use App\Modules\IdentityAccess\Infrastructure\Http\Middleware\EnsureSessionIsCurrent;
use App\Modules\ModuleServiceProvider;
use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

final class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Invitations::class, InvitationService::class);
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->tag([ProvisionCompanyRoles::class], CompanyProvisioningStep::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Router::class)->aliasMiddleware('session.current', EnsureSessionIsCurrent::class);

        // Le super-administrateur dispose de toutes les permissions dans l'entreprise active (docs/04 §2).
        $this->app->make(Gate::class)->before(
            fn (mixed $user) => $user instanceof CompanyMember && $user->isSuperAdmin() ? true : null,
        );

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinutes(15, 5)->by((string) $request->ip()));

        // Longueur ≥ 12 ; en production, refus des mots de passe connus comme compromis (docs/02 §7).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->uncompromised()
            : Password::min(12)->letters()->numbers());

        if ($this->app->runningInConsole()) {
            $this->commands([CreateSuperAdminCommand::class]);
        }
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
