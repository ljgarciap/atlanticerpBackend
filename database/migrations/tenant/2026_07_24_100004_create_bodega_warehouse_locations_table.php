<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-454 (REQ-384, chip "Espacio libre") — catálogo EDITABLE de ubicaciones físicas por
 * bodega, decisión de Luis 2026-07-24 (ver ADR-SCRUM454-459-reubicacion-bodegas.md): a diferencia
 * del catálogo fijo de 7 bodegas (SCRUM-451, sembrado por migración, sin CRUD), la disposición de
 * racks/ubicaciones dentro de una bodega cambia con el tiempo sin que eso deba requerir un
 * deploy — CRUD completo vía `WarehouseLocationController`, gateado a `bodega.write`.
 *
 * Vacías (para el chip) = `warehouse_locations` activas de la bodega MENOS las `ubicacion` con
 * `product_warehouse_stock.quantity > 0` de esa misma bodega — ver `WarehouseController::show()`.
 * `is_active` en vez de hard-delete para no perder historial de qué ubicaciones existieron.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::create($this->table('warehouse_locations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'warehouse_locations_warehouse_fk')
                ->cascadeOnDelete();
            $table->string('codigo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'codigo'], 'warehouse_locations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('warehouse_locations'));
    }
};
