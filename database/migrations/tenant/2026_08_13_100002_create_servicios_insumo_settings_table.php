<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-273/275, SCRUM-343/345) — qué productos del Catálogo
 * trackea la "Reserva Servicios" + su mínimo LOCAL (umbral configurable de negocio, nunca
 * hardcodeado — regla global del CLAUDE.md).
 *
 * Decisión técnica (dentro del diseño ya aprobado, documentada acá porque el ticket original dejó
 * 2 caminos abiertos): NO se reusa `ventas_diseno.catalog_products.reorder_point` para este
 * mínimo. Esa columna ya es el umbral de "bajo stock" que usan Compras (`InventoryKpiService`,
 * `ComprasHomeService`) y Bodega (`BodegaInventoryKpiService`, `WarehouseController`) para el
 * inventario GENERAL del producto — reusarla acá pisaría ese significado con un umbral de
 * Servicios que puede ser completamente distinto (Servicios necesita un mínimo bajo para un
 * insumo de consumo menor, mientras Compras/Bodega miran el stock agregado de TODAS las bodegas).
 * Esta tabla nueva mantiene ambos conceptos independientes: el mismo producto puede tener un
 * `reorder_point` general de 50 unidades (vista de Compras) y un `minimo` de Reserva Servicios de
 * 5 unidades (vista de Servicios), sin que uno pise al otro.
 *
 * `catalog_product_id` único: un producto solo puede trackearse una vez en la Reserva Servicios
 * (REQ-275 RN implícita — "excluye insumos que ya están en la lista").
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('insumo_settings'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->unique('servicios_insumo_settings_product_uq')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'servicios_insumo_settings_product_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('minimo')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('insumo_settings'));
    }
};
