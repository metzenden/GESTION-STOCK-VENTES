<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Infrastructure\Permissions\PermissionCatalogSynchronizer;
use Illuminate\Database\Migrations\Migration;

/** Lot 3 : permission goods_receipts.accept_surplus (IMP-008), ajoutée aux modèles et aux rôles Administrateur existants. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionCatalogSynchronizer::class)->sync();
    }

    public function down(): void {}
};
