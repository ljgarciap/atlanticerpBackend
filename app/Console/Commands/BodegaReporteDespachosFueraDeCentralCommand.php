<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1196 — reporte de SOLO LECTURA para que Bodega cuente y ajuste a mano los datos que dejó el comportamiento anterior (el sistema escogía la bodega de
 * salida con más unidades, sin importar si era de venta): (A) despachos desde `--desde` que salieron de una bodega distinta a la de despacho por defecto,
 * (B) productos con alguna bodega en saldo negativo (con su Stock total y la suma por bodega), (C) pedidos EN VUELO (picking, packing, por despachar) que
 * apuntan a una bodega que no es de venta. NO modifica ningún dato; los ajustes de inventario los hace Bodega después de contar.
 */
final class BodegaReporteDespachosFueraDeCentralCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:reporte-despachos-fuera-de-central
        {--tenant=atlantic : Slug del tenant}
        {--desde=2026-10-16 : Fecha (YYYY-MM-DD) desde la que se buscan despachos}
        {--csv= : Ruta de un archivo CSV donde guardar las 3 secciones (opcional)}';

    /** @var string */
    protected $description = 'SCRUM-1196 — reporte (solo lectura) de despachos fuera de la bodega por defecto, productos con bodegas en negativo y pedidos en vuelo con bodega no permitida';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $desde = (string) $this->option('desde');
        $default = Warehouse::despachoPorDefecto();
        if ($default === null) {
            $this->error('No hay una bodega de despacho por defecto definida.');

            return self::FAILURE;
        }
        $names = Warehouse::query()->pluck('name', 'id');

        // A — despachos fuera de la bodega por defecto
        $a = [];
        $moves = InventoryMovement::query()
            ->where('tipo', InventoryMovement::TIPO_SALIDA)->where('referencia_entidad_tipo', 'PEDIDO')
            ->where('created_at', '>=', $desde)->where('warehouse_id', '!=', $default->id)
            ->orderBy('created_at')->with('catalogProduct')->get();
        $orderNumbers = Order::query()->whereIn('id', $moves->pluck('referencia_entidad_id')->filter()->unique())->pluck('order_number', 'id');
        foreach ($moves as $m) {
            $a[] = [
                $m->created_at->format('Y-m-d H:i'), $orderNumbers[$m->referencia_entidad_id] ?? ('#'.$m->referencia_entidad_id),
                $m->catalogProduct->reference ?? ('#'.$m->catalog_product_id), $names[$m->warehouse_id] ?? $m->warehouse_id, (int) $m->cantidad,
            ];
        }

        // B — productos con alguna bodega en negativo
        $b = [];
        $negatives = ProductWarehouseStock::query()->enBodegaOperativa()->where('quantity', '<', 0)->with('catalogProduct')->orderBy('catalog_product_id')->get();
        foreach ($negatives->groupBy('catalog_product_id') as $productId => $rows) {
            $product = $rows->first()?->catalogProduct;
            $all = ProductWarehouseStock::query()->enBodegaOperativa()->where('catalog_product_id', $productId)->get();
            $detalle = $all->map(fn (ProductWarehouseStock $r): string => ($names[$r->warehouse_id] ?? $r->warehouse_id).': '.$r->quantity)->implode(' | ');
            $b[] = [$product->reference ?? ('#'.$productId), (int) ($product->stock_quantity ?? 0), (int) $all->sum('quantity'), $detalle];
        }

        // C — pedidos en vuelo con bodega no permitida para ventas
        $saleIds = Warehouse::paraVenta()->pluck('id')->all();
        $c = [];
        $items = OrderItem::query()->whereNotNull('warehouse_id')->whereNotIn('warehouse_id', $saleIds)
            ->whereHas('order', fn ($q) => $q->whereIn('stage', [Order::STAGE_EN_PICKING, Order::STAGE_PACKING, Order::STAGE_POR_DESPACHAR]))
            ->with(['order', 'catalogProduct'])->get();
        foreach ($items as $i) {
            $c[] = [$i->order->order_number, $i->order->stage, $i->catalogProduct->reference ?? $i->reference_snapshot, $names[$i->warehouse_id] ?? $i->warehouse_id, $i->qty_reserved, $i->qty_picked];
        }

        $hA = ['Fecha', 'Pedido', 'Producto', 'Bodega de salida', 'Cantidad'];
        $hB = ['Producto', 'Stock total (catálogo)', 'Suma de bodegas', 'Saldo por bodega'];
        $hC = ['Pedido', 'Etapa', 'Producto', 'Bodega asignada', 'Reservado', 'Alistado'];

        $this->info("A. Despachos desde {$desde} que NO salieron de «{$default->name}»: ".count($a));
        $this->comment('Incluye las piezas de otra bodega de venta (p. ej. Showroom Obarrio) que Bodega escogió a propósito en el picking; revisar sobre todo las de bodegas que no son de venta.');
        $this->table($hA, $a);
        $this->info('B. Productos con alguna bodega en negativo: '.count($b));
        $this->table($hB, $b);
        $this->info('C. Pedidos en vuelo con bodega que no es de venta: '.count($c));
        $this->table($hC, $c);

        $csv = (string) $this->option('csv');
        if ($csv !== '') {
            $fh = fopen($csv, 'w');
            if ($fh === false) {
                $this->error("No se pudo escribir {$csv}.");

                return self::FAILURE;
            }
            foreach ([['A. Despachos fuera de la bodega por defecto', $hA, $a], ['B. Productos con bodegas en negativo', $hB, $b], ['C. Pedidos en vuelo con bodega no permitida', $hC, $c]] as [$title, $head, $rows]) {
                fputcsv($fh, [$title]);
                fputcsv($fh, $head);
                foreach ($rows as $row) {
                    fputcsv($fh, $row);
                }
                fputcsv($fh, []);
            }
            fclose($fh);
            $this->info("CSV guardado en {$csv}");
        }

        $this->comment('Solo lectura: no se modificó ningún dato.');

        return self::SUCCESS;
    }
}
