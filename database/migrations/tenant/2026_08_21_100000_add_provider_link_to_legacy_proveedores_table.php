<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Épica SCRUM-749 (Migración ICG→AtlanticERP). Reabre la decisión original de `legacy.proveedores`
 * ("SIN columnas de linkeo... nunca se agregan a propósito", ver docblock de
 * `2026_08_14_120000_create_legacy_proveedores_table.php`) — decisión explícita de Luis
 * 2026-08-21: convertir Clientes/Proveedores recurrentes en activos de valor real de AtlanticERP, no solo
 * referencia de solo lectura. Mismo patrón cross-schema ya usado por `legacy_productos_
 * catalog_product_fk`/`legacy_clientes_sub_client_fk`.
 *
 * `match_status` (mismo vocabulario que `legacy.clientes`, pero semántica distinta — Proveedores
 * nunca tuvo matching antes de esta migración):
 *   - `unmatched` (default) — todavía no evaluado por `App\Modules\Legacy\Services\
 *     ProveedorFusionLoader`.
 *   - `matched` — el nombre normalizado coincidió con un `compras.providers` YA EXISTENTE (creado a
 *     mano antes de esta migración) — se linkea, nunca se duplica.
 *   - `promoted` — no había match por nombre; se creó un `compras.providers` nuevo a partir de este
 *     registro de ICG.
 *
 * Umbral de recurrencia (≥1 artículo en `legacy.producto_costos`, decisión de Luis 2026-08-21) vive
 * en `ProveedorFusionLoader`, no en el schema — evita tener que migrar de nuevo si el criterio
 * cambia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        $comprasSchema = Tenant::current()->slug.'_compras';

        Schema::table($this->table('proveedores'), function (Blueprint $table) use ($comprasSchema): void {
            $table->foreignId('provider_id')->nullable()
                ->constrained("{$comprasSchema}.providers", 'id', 'legacy_proveedores_provider_fk')
                ->nullOnDelete();
            $table->string('match_status', 20)->default('unmatched'); // unmatched | matched | promoted

            $table->index('match_status');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('proveedores'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn('match_status');
        });
    }
};
