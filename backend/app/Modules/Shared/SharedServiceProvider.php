<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\ModuleServiceProvider;
use App\Modules\Shared\Contracts\PdfRenderer;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Console\HealthCommand;
use App\Modules\Shared\Infrastructure\Database\SchemaMacros;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use App\Modules\Shared\Infrastructure\Http\Middleware\EnforceIdempotency;
use App\Modules\Shared\Infrastructure\Http\ProblemDetailsRenderer;
use App\Modules\Shared\Infrastructure\Pdf\GotenbergPdfRenderer;
use App\Modules\Shared\Infrastructure\Tenancy\RequestTenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;

final class SharedServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        SchemaMacros::register();

        // Un contexte par requête (ou par job, rétabli via runAs).
        $this->app->scoped(TenantContext::class, RequestTenantContext::class);

        $this->app->singleton(ProblemDetailsRenderer::class, fn ($app) => new ProblemDetailsRenderer((bool) $app['config']->get('app.debug')));

        $this->app->bind(PdfRenderer::class, fn ($app) => new GotenbergPdfRenderer(
            $app->make(HttpFactory::class),
            (string) $app->make(Config::class)->get('services.gotenberg.url', 'http://gotenberg:3000'),
        ));

        $config = $this->app->make(Config::class);
        $config->set('filesystems.disks.'.PrivateFileStorage::DISK, $config->get(
            'filesystems.disks.'.PrivateFileStorage::DISK,
            ['driver' => 'local', 'root' => storage_path('app/private'), 'visibility' => 'private', 'throw' => true, 'serve' => false],
        ));
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Router::class)->aliasMiddleware('idempotent', EnforceIdempotency::class);

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class]);

            $this->callAfterResolving(Schedule::class, function ($schedule) {
                $schedule->call(fn () => $this->app->make(ConnectionInterface::class)->table('idempotency_keys')->where('expires_at', '<', now())->delete())
                    ->hourly()->name('purge-idempotency-keys')->withoutOverlapping();
            });
        }
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
