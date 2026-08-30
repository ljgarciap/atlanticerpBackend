<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 15 de Admin&Cont (SCRUM-580→584, REQ-506) — ver
 * docs/adr/ADR-SCRUM580-584-batch15-comisiones-internas.md §4. `compartido_con` (JSONB nullable,
 * array de `user_id`) identifica a los demás responsables de un proyecto compartido, además del
 * `owner_id` original — `CommissionOrderService` divide `amount` en partes iguales entre
 * `owner_id` + `compartido_con` cuando no está vacío.
 *
 * Deliberadamente sin UI de asignación en este batch — el propio REQ-506 asume "Dado que un
 * proyecto... se comparte" como precondición dada, no pide construir el flujo de asignación (eso
 * es una feature de Ventas&Diseño, otra épica, fuera de los 148 tickets de Admin&Cont). El campo
 * queda vacío en producción hasta que esa UI exista en otro lado — acá solo se prepara el cálculo.
 *
 * Migración cross-módulo (Admin&Cont modificando una tabla de `ventas_diseno`) — sin problema,
 * es una columna nueva, no una FK cross-schema (esas siguen prohibidas en este proyecto).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->jsonb('compartido_con')->nullable()->after('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropColumn('compartido_con');
        });
    }
};
