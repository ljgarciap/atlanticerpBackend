<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-973 (Pre-QA, épica SCRUM-969 — integración Lafayette Lighting) — hallazgo CRÍTICO real:
 * `WarehouseController::show()` filtra `quantity > 0`, ocultando el 69% del catálogo de Lafayette
 * (17,848 de 25,816 productos con `disponible=0` en la fuente) de `Bodega > Bodegas > Zona Libre`.
 *
 * Decisión de Luis 2026-09-26: "Bodega Zona Libre es una bodega independiente... no podemos ni
 * debemos ignorar las cantidades ni la data que trae" — es un catálogo de lo que el proveedor
 * PUEDE proveer, no inventario físico propio, así que debe listar todo aunque tenga 0 unidades.
 *
 * Mismo patrón que `excluye_disponible` (SCRUM-851) — flag booleano reusable por bodega en vez de
 * hardcodear el nombre "Bodega Zona Libre" en el controller.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->boolean('mostrar_sin_stock')->default(false)->after('excluye_disponible');
        });

        DB::table($this->table('warehouses'))
            ->where('name', 'Bodega Zona Libre')
            ->update(['mostrar_sin_stock' => true]);
    }

    public function down(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->dropColumn('mostrar_sin_stock');
        });
    }
};
