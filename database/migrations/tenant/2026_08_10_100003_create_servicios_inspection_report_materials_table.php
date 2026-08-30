<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-240 RN1/RN2) — materiales/insumos usados en la visita,
 * capturados como texto libre, SIN FK a `catalog_products` a propósito (RN1). RN3 (disparar un
 * movimiento real de Consumo en el kardex de Bodega) y RN4 (alerta de sobre-consumo) quedan
 * DIFERIDOS a Batch 14 — decisión de Luis 2026-08-10, ver docblock de
 * `2026_08_10_100002_create_servicios_inspection_reports_table.php` y
 * docs/architecture/servicios-fase4-diseno.md sección 4. Este batch persiste el registro
 * puramente informativo; no se llama a `KardexService` desde acá todavía.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('inspection_report_materials'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_report_id')
                ->constrained($this->table('inspection_reports'), 'id', 'servicios_insp_report_materials_report_fk')
                ->cascadeOnDelete();

            $table->string('nombre_material');
            $table->string('cantidad'); // texto libre (RN1) — no numérico a propósito

            $table->index('inspection_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inspection_report_materials'));
    }
};
