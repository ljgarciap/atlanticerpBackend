<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-455/456 (REQ-385/386, entidad UBICACION_PRODUCTO del Modelo de Datos) — agrega el detalle
 * de ubicación física y, para bodegas modo "cliente", a qué cliente pertenece la unidad en
 * exhibición. Se agrega a `product_warehouse_stock` en vez de crear una tabla paralela — Compras
 * (Ver Inventario) y Bodega (pantalla Bodegas) deben leer de la misma fuente (decisión de Luis,
 * 2026-07-21), solo mostrando columnas distintas.
 *
 * `ubicacion` nulo = "pendiente" (bodegas modo `pendiente`, ver Warehouse::MODO_PENDIENTE) o
 * simplemente no capturada todavía. `cliente_id` solo aplica en bodegas modo `cliente`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::table($this->table('product_warehouse_stock'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->string('ubicacion')->nullable()->after('warehouse_id');
            $table->foreignId('cliente_id')->nullable()->after('ubicacion')
                ->constrained("{$ventasDisenoSchema}.master_clients", 'id', 'product_warehouse_stock_cliente_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('product_warehouse_stock'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropColumn('ubicacion');
        });
    }
};
