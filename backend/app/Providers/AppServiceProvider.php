<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Hors production : chargement paresseux (N+1), attributs inconnus et affectations silencieuses lèvent une erreur.
        Model::shouldBeStrict(! $this->app->isProduction());

        // L7-03 : limitation de débit générale de l'API, par utilisateur (à défaut par adresse IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('app.api_rate_limit', 600))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(base_path('tests/Support/Migrations'));
        }
    }
}
