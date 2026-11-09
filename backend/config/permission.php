<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Infrastructure\Models\Permission;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Permissions\TenantTeamResolver;

/*
| spatie/laravel-permission en mode teams : l'équipe est l'entreprise active (D-006).
| Le schéma est créé par la migration du module IdentityAccess (docs/03 §3).
*/
return [
    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'company_id',
    ],

    'register_permission_check_method' => true,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,
    'teams' => true,
    'team_resolver' => TenantTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
