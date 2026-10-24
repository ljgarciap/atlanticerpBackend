<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1116 — `CreditableBalanceService` suma las notas de una factura por `(factura_origen_id, estado)` en cada
 * registro, aprobación y carga del listado de facturas del modal. PostgreSQL no indexa solo las claves foráneas, así
 * que sin este índice esa suma recorre toda la tabla. Aditivo: no cambia datos ni columnas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->index(['factura_origen_id', 'estado'], 'credit_notes_factura_origen_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->dropIndex('credit_notes_factura_origen_estado_idx');
        });
    }
};
