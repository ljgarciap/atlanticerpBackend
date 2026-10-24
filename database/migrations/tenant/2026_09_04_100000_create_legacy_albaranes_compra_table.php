<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-840 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de ALBCOMPRACAB — equivalente de
 * compras a `legacy.albaranes` (ALBVENTACAB, SCRUM-759): mismo patrón exacto, mismo tipo de llave
 * natural (icg_n como texto, ver docblock de `2026_08_19_100000_create_legacy_albaranes_table.php`
 * para el gotcha ya confirmado en las 6 tablas hermanas de ICG).
 *
 * Vincula por `icg_codproveedor` → `legacy.proveedores.icg_codproveedor` (lookup simple, resuelve
 * `App\Modules\Legacy\Services\AlbaranCompraMatcher`) — NO por cliente, a diferencia de los
 * Albaranes de Venta. `legacy_proveedor_id`/`orphan` nunca se incluyen en el `$update` del upsert
 * de staging, mismo criterio que el resto de entidades con Matcher.
 *
 * `icg_numseriefac`/`icg_numfac` — link crudo a la Factura de Compra, sin resolver (esa entidad no
 * está en alcance de este ticket, igual que el link a Factura de Venta quedó crudo en
 * `legacy.albaranes` hasta que existió SCRUM-760).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('albaranes_compra'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_numserie', 10);
            $table->unsignedInteger('icg_numalbaran');
            $table->string('icg_n', 10);
            $table->unique(
                ['icg_numserie', 'icg_numalbaran', 'icg_n'],
                'legacy_albaranes_compra_natural_key_uq',
            );

            $table->foreignId('legacy_proveedor_id')->nullable()
                ->constrained($this->table('proveedores'), 'id', 'legacy_albaranes_compra_proveedor_fk')
                ->nullOnDelete();
            $table->unsignedInteger('icg_codproveedor')->nullable();

            $table->timestampTz('fecha')->nullable();
            $table->decimal('totalneto', 14, 2)->nullable();
            $table->boolean('facturado')->default(false);

            // Link crudo a la Factura de Compra — sin resolver en este batch (esa entidad no está
            // en el alcance de la épica todavía).
            $table->string('icg_numseriefac', 10)->nullable();
            $table->unsignedInteger('icg_numfac')->nullable();

            $table->boolean('orphan')->default(false);

            $table->timestampTz('created_at')->nullable();

            $table->index('icg_codproveedor');
            $table->index('orphan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('albaranes_compra'));
    }
};
