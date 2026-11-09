<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Raccourcis de migration reprenant les conventions de docs/03-modele-donnees.md §1.
 */
final class SchemaMacros
{
    public static function register(): void
    {
        Blueprint::macro('ulidPrimary', function (string $column = 'id'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 26)->charset('ascii')->collation('ascii_bin')->primary();
        });

        Blueprint::macro('ulidColumn', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 26)->charset('ascii')->collation('ascii_bin');
        });

        // Colonne company_id + FK + UNIQUE (company_id, id) servant de cible aux FK composites (D-003).
        Blueprint::macro('tenant', function (): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $this->char('company_id', 26)->charset('ascii')->collation('ascii_bin');
            $this->unique(['company_id', 'id'], "uq_{$table}_tenant");
            $this->foreign('company_id', "fk_{$table}_company")->references('id')->on('companies')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        // Clé étrangère intra-entreprise : (company_id, colonne) → parent(company_id, id).
        Blueprint::macro('tenantForeign', function (string $column, string $parentTable, ?string $name = null): void {
            /** @var Blueprint $this */
            $this->foreign(['company_id', $column], $name ?? "fk_{$this->getTable()}_{$column}")
                ->references(['company_id', 'id'])->on($parentTable)
                ->restrictOnDelete()->restrictOnUpdate();
        });

        Blueprint::macro('money', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 19, 4);
        });

        Blueprint::macro('unitCost', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 24, 8);
        });

        Blueprint::macro('rate', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 20, 10);
        });

        Blueprint::macro('quantity', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->bigInteger($column);
        });

        Blueprint::macro('currencyCode', function (string $column = 'currency'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 3)->charset('ascii')->collation('ascii_bin');
        });

        // Horodatages en microsecondes + auteurs (colonnes standard « STD »).
        Blueprint::macro('auditColumns', function (): void {
            /** @var Blueprint $this */
            $this->timestamp('created_at', 6)->useCurrent();
            $this->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $this->char('created_by', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            $this->char('updated_by', 26)->charset('ascii')->collation('ascii_bin')->nullable();
        });
    }
}
