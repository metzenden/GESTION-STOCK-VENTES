<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contraintes que le Blueprint Laravel ne sait pas exprimer : CHECK et immutabilité (D-038).
 */
final class Constraints
{
    public static function check(string $table, string $name, string $expression): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($name);

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /**
     * Rend une table append-only : toute modification ou suppression de ligne lève une erreur SQL.
     * Les corrections passent par des contre-écritures (GEN-001).
     */
    public static function appendOnly(string $table): void
    {
        self::assertIdentifier($table);

        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $event => $suffix) {
            // @phpstan-ignore argument.type (identifiants validés par assertIdentifier)
            DB::unprepared("DROP TRIGGER IF EXISTS `trg_{$table}_no_{$suffix}`");
            // @phpstan-ignore argument.type (identifiants validés par assertIdentifier)
            DB::unprepared(<<<SQL
                CREATE TRIGGER `trg_{$table}_no_{$suffix}` BEFORE {$event} ON `{$table}`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Table {$table} en ajout seul : {$suffix} interdit'
                SQL);
        }
    }

    public static function dropAppendOnly(string $table): void
    {
        self::assertIdentifier($table);

        // @phpstan-ignore argument.type (identifiants validés par assertIdentifier)
        DB::unprepared("DROP TRIGGER IF EXISTS `trg_{$table}_no_update`");
        // @phpstan-ignore argument.type (identifiants validés par assertIdentifier)
        DB::unprepared("DROP TRIGGER IF EXISTS `trg_{$table}_no_delete`");
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Identifiant SQL invalide : {$identifier}");
        }
    }
}
