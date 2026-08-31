<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-315 (SCRUM-385, spec de Gerencia Test) — la Hoja de Picking define una columna
 * "Observación" (nota de texto libre por línea, capturada por el picker) que nunca se
 * implementó en el batch original. No es lo mismo que `shortage_reason` (catálogo fijo de
 * motivos de faltante, REQ-324 RN1) — esta es una nota libre, opcional, sin semántica de
 * negocio propia, así que no aplica la regla de "tabla paramétrica" ni ningún catálogo fijo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->text('picking_notes')->nullable()->after('shortage_reason');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->dropColumn('picking_notes');
        });
    }
};
