<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Infrastructure\EloquentCompanyDirectory;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\EnsureCompanyWritable;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\EnsureSuperAdmin;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\ResolveCompanyContext;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use App\Modules\Tenancy\Infrastructure\Queue\RestoreTenantContext;
use Illuminate\Bus\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyDirectory::class, EloquentCompanyDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('company.context', ResolveCompanyContext::class);
        $router->aliasMiddleware('company.writable', EnsureCompanyWritable::class);
        $router->aliasMiddleware('super-admin', EnsureSuperAdmin::class);

        // Liaison de route : le super-admin désigne une entreprise par son identifiant (routes /admin uniquement).
        Route::model('company', Company::class);

        $this->app->make(Dispatcher::class)->pipeThrough([RestoreTenantContext::class]);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
