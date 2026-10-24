<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1196 — los despachos salen de Bodega Central por defecto (reglas de Esteban Cárdenas, Líder de Bodega, 22-10-2026).
 *
 * `warehouses.permite_venta`: la bodega puede surtir pedidos de venta (Central y Showroom Obarrio). `despacho_por_defecto`: la que el picking propone
 * (exactamente una, índice único parcial). Ambas son datos editables por superadmin (panel de Configuración de Bodega), no constantes del código.
 *
 * `excluye_disponible` (SCRUM-851, fórmula única `InventoryKpiService::disponibleParaVenta`) pasa a verdadero también para los 3 showrooms: el
 * «Disponible» que ven los vendedores cuenta solo Bodega Central. Lo de Showroom Obarrio (`permite_venta` Y `excluye_disponible`) se muestra aparte.
 *
 * `order_item_allocations`: una línea de pedido puede repartirse entre bodegas (10 de Central + 1 de Obarrio, Kardex −10/−1). `order_items.warehouse_id /
 * qty_reserved / qty_picked` se conservan como el TOTAL y la bodega principal (facturación, documentos e informes los leen). Un pedido sin filas aquí
 * (históricos y los que estaban en vuelo antes de esta migración) sigue resolviéndose por `order_items.warehouse_id`, igual que antes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    private const CENTRAL = 'Bodega Central';

    /** @var list<string> */
    private const PARA_VENTA = ['Bodega Central', 'Showroom Obarrio'];

    /** @var list<string> */
    private const EXCLUYEN_DISPONIBLE = ['Showroom Obarrio', 'Showroom Cliente', 'Showroom SM'];

    public function up(): void
    {
        $warehouses = $this->table('warehouses');

        Schema::table($warehouses, function (Blueprint $table): void {
            $table->boolean('permite_venta')->default(false)->after('excluye_disponible');
            $table->boolean('despacho_por_defecto')->default(false)->after('permite_venta');
        });

        // Exactamente una bodega de despacho por defecto, y solo entre las de venta (la validación del panel lo repite a nivel de aplicación).
        DB::statement('CREATE UNIQUE INDEX warehouses_despacho_por_defecto_unique ON '.$warehouses.' (despacho_por_defecto) WHERE despacho_por_defecto');
        DB::statement('ALTER TABLE '.$warehouses.' ADD CONSTRAINT warehouses_defecto_exige_venta CHECK (NOT despacho_por_defecto OR permite_venta)');

        if (! DB::table($warehouses)->where('name', self::CENTRAL)->exists()) {
            throw new RuntimeException('SCRUM-1196: no existe la bodega «'.self::CENTRAL.'»; no se puede sembrar la bodega de despacho por defecto.');
        }

        DB::table($warehouses)->whereIn('name', self::PARA_VENTA)->update(['permite_venta' => true]);
        DB::table($warehouses)->where('name', self::CENTRAL)->update(['despacho_por_defecto' => true, 'excluye_disponible' => false]);
        DB::table($warehouses)->whereIn('name', self::EXCLUYEN_DISPONIBLE)->update(['excluye_disponible' => true]);

        Schema::create($this->table('order_item_allocations'), function (Blueprint $table) use ($warehouses): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained($this->table('order_items'), 'id', 'order_item_allocations_item_fk')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained($warehouses, 'id', 'order_item_allocations_warehouse_fk')->restrictOnDelete();
            $table->unsignedInteger('qty_reserved')->default(0);
            $table->unsignedInteger('qty_picked')->default(0);
            // true = la eligió Bodega en el picking (p. ej. 1 pieza de Showroom Obarrio); false = la reserva automática en la bodega por defecto.
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['order_item_id', 'warehouse_id'], 'order_item_allocations_unique');
            $table->index('warehouse_id', 'order_item_allocations_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('order_item_allocations'));

        $warehouses = $this->table('warehouses');
        DB::statement('ALTER TABLE '.$warehouses.' DROP CONSTRAINT IF EXISTS warehouses_defecto_exige_venta');
        DB::statement('DROP INDEX IF EXISTS '.$this->schema().'.warehouses_despacho_por_defecto_unique');

        Schema::table($warehouses, function (Blueprint $table): void {
            $table->dropColumn(['permite_venta', 'despacho_por_defecto']);
        });

        // Vuelve al estado de SCRUM-851: solo Reserva Servicios, Merma y Reclamos excluyen del disponible.
        DB::table($warehouses)->whereIn('name', self::EXCLUYEN_DISPONIBLE)->update(['excluye_disponible' => false]);
    }
};
