<?php

declare(strict_types=1);

/*
| Règles d'architecture (docs/02 §2.2, D-037), vérifiées pour chaque module présent.
*/

$modules = array_map('basename', glob(__DIR__.'/../../app/Modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    arch("{$module} : la couche Domain ne dépend ni de Laravel ni de l'infrastructure")
        ->expect("App\\Modules\\{$module}\\Domain")
        ->not->toUse(['Illuminate', 'Laravel', "App\\Modules\\{$module}\\Infrastructure", "App\\Modules\\{$module}\\Http"]);

    foreach ($modules as $other) {
        if ($other === $module || $other === 'Shared') {
            continue;
        }

        arch("{$module} n'utilise de {$other} que ses contrats publics")
            ->expect("App\\Modules\\{$module}")
            ->not->toUse("App\\Modules\\{$other}")
            ->ignoring("App\\Modules\\{$other}\\Contracts");
    }
}

arch('Shared ne dépend d\'aucun autre module')
    ->expect('App\Modules\Shared')
    ->not->toUse(array_map(fn ($m) => "App\\Modules\\{$m}", array_values(array_diff($modules, ['Shared']))));

arch('aucune fonction de débogage oubliée')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'print_r'])
    ->not->toBeUsed();

arch('typage strict partout dans les modules')
    ->expect('App\Modules')
    ->toUseStrictTypes();
