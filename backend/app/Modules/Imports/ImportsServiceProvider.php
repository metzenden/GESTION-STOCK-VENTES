<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Imports\Application\EloquentMappingTemplates;
use App\Modules\Imports\Contracts\MappingTemplates;
use App\Modules\Imports\Contracts\TabularImporter;
use App\Modules\Imports\Infrastructure\Readers\SpreadsheetImporter;
use App\Modules\ModuleServiceProvider;

final class ImportsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TabularImporter::class, SpreadsheetImporter::class);
        $this->app->bind(MappingTemplates::class, EloquentMappingTemplates::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
