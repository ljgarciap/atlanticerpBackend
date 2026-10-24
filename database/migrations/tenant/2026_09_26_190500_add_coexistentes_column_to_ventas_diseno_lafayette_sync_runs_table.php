<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-973 (Pre-QA, épica SCRUM-969) — decisión de Luis 2026-09-26: un producto de Lafayette que
 * colisiona en `reference` con uno de OTRO PROVEEDOR REAL ya no se vincula/sobrescribe (revierte
 * parcialmente la decisión de `vinculados`, ver docblock de `CatalogProductLoader`) — se crea como
 * producto separado que coexiste. `coexistentes` cuenta esos casos por separado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->unsignedInteger('coexistentes')->default(0)->after('vinculados');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->dropColumn('coexistentes');
        });
    }
};
