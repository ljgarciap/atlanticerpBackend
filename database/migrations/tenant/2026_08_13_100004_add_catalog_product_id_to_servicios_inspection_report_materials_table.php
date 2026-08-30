<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-240 RN3/RN4, REQ-291 RN3(c)/RN4, SCRUM-361) — conecta
 * el registro de materiales del Informe de Inspección (Batch 8, hasta ahora puramente texto libre
 * SIN FK a `catalog_products` "a propósito", ver docblock de
 * `2026_08_10_100003_create_servicios_inspection_report_materials_table.php`) con el Kardex real.
 *
 * Decisión técnica (dentro del diseño ya aprobado — el ticket original de Batch 8 diseñó
 * `nombre_material`/`cantidad` como texto libre sin prever el Batch 14/Grupo D parte 2 de hoy, así
 * que no hay forma de mapear 1:1 un material histórico a un movimiento de Kardex real sin
 * inventar un producto): esta columna es ADITIVA y NULLABLE, no reemplaza el texto libre.
 * `nombre_material`/`cantidad` siguen existiendo sin cambios para cualquier material que sea
 * puramente informativo (ej. "silicón", "cinta aislante" — cosas que Servicios no trackea en su
 * Reserva). Cuando el técnico SÍ selecciona un insumo real de la Reserva Servicios (picker nuevo
 * del frontend, catálogo filtrado a `insumo_settings`), `catalog_product_id` queda seteado y
 * `InspectionReportService` genera el movimiento de Consumo automático al completar el informe
 * (ver su docblock) — solo para esas líneas. Un material con `catalog_product_id = NULL` (todo el
 * histórico anterior a este batch, y cualquier material nuevo no trackeado) nunca afecta stock,
 * exactamente como se comportaba antes de este batch.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::table($this->table('inspection_report_materials'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->foreignId('catalog_product_id')->nullable()->after('inspection_report_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'servicios_insp_report_materials_product_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('inspection_report_materials'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_product_id');
        });
    }
};
