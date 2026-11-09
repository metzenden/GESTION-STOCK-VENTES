<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Données de référence créées par les migrations, conservées par DatabaseTruncation. */
    protected array $exceptTables = ['currencies', 'permissions', 'roles', 'role_has_permissions'];

    protected function setUp(): void
    {
        parent::setUp();

        // Requêtes « issues de la SPA » : Sanctum active la session (comportement de production).
        $this->withHeader('Referer', 'http://localhost/');
    }
}
