<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-971 (épica SCRUM-969) — decisión de Luis 2026-09-26 tras la primera corrida real: un
 * producto de Lafayette que colisiona en `reference` con uno de otra fuente ya NO se excluye, se
 * "vincula" (reclama y actualiza) — ver docblock de `CatalogProductLoader`. `vinculados` cuenta
 * esos casos por separado de `creados`/`actualizados` para trazabilidad real: la primera corrida
 * ya mostró que puede ser un volumen grande (8,012 de 25,816).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->unsignedInteger('vinculados')->default(0)->after('creados');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->dropColumn('vinculados');
        });
    }
};
