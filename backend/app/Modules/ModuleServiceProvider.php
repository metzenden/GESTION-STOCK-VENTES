<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Fournisseur de base d'un module : charge ses migrations (Database/Migrations)
 * et ses routes d'API (Http/routes.php, préfixe /api/v1, groupe « api »).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $migrations = $this->modulePath().'/Database/Migrations';

        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        $routes = $this->modulePath().'/Http/routes.php';

        if (is_file($routes) && ! $this->app->routesAreCached()) {
            Route::prefix('api/v1')->middleware('api')->group($routes);
        }
    }
}
