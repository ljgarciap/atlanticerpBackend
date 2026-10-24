<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\StockReservationService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1196 — al desplegar las reglas de bodega de venta hay pedidos EN PICKING que ya habían reservado una bodega que no es de venta (Reclamos, Merma...).
 * Este comando recalcula la reserva de los que NO han alistado nada (`qty_picked = 0`): pasan a la bodega de despacho por defecto, o quedan sin reservar si no
 * alcanza. Los ya alistados o en Packing / Por despachar NO se tocan (ya hay movimiento físico): solo se listan para que Bodega decida.
 * Simulación por defecto; `--force` aplica. Idempotente.
 */
final class BodegaReasignarPedidosEnVueloCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:reasignar-pedidos-en-vuelo
        {--tenant=atlantic : Slug del tenant}
        {--force : Aplica los cambios (sin esto solo simula)}';

    /** @var string */
    protected $description = 'SCRUM-1196 — reasigna a la bodega de despacho por defecto los pedidos en picking sin alistar que apuntan a una bodega que no es de venta (simulación por defecto)';

    public function __construct(private readonly StockReservationService $reservations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $force   = (bool) $this->option('force');
        $saleIds = Warehouse::paraVenta()->pluck('id')->all();
        $names   = Warehouse::query()->pluck('name', 'id');

        $items = OrderItem::query()->whereNotNull('warehouse_id')->whereNotIn('warehouse_id', $saleIds)->with(['order', 'catalogProduct'])
            ->whereHas('order', fn ($q) => $q->whereIn('stage', [Order::STAGE_EN_PICKING, Order::STAGE_PACKING, Order::STAGE_POR_DESPACHAR]))->get();

        $reassign = $items->filter(fn (OrderItem $i): bool => $i->order->stage === Order::STAGE_EN_PICKING && $i->qty_picked === 0);
        $skipped  = $items->diff($reassign);

        $fmt = fn (OrderItem $i): array => [$i->order->order_number, $i->order->stage, $i->catalogProduct->reference ?? $i->reference_snapshot, $names[$i->warehouse_id] ?? $i->warehouse_id, $i->qty_reserved, $i->qty_picked];
        $head = ['Pedido', 'Etapa', 'Producto', 'Bodega asignada', 'Reservado', 'Alistado'];

        $this->info(($force ? 'Se reasignan' : 'Se reasignarían').' (en picking, sin alistar): '.$reassign->count());
        $this->table($head, $reassign->map($fmt)->values()->all());
        $this->warn('NO se tocan (ya alistados o en Packing / Por despachar — Bodega decide): '.$skipped->count());
        $this->table($head, $skipped->map($fmt)->values()->all());

        if (! $force) {
            $this->comment('Simulación: no se cambió nada. Usa --force para aplicar.');

            return self::SUCCESS;
        }

        /** @var list<int> $productIds */
        $productIds = array_values(array_map('intval', $reassign->pluck('catalog_product_id')->filter()->unique()->all()));
        $this->reservations->reallocateForProducts($productIds);
        $this->info('Reservas recalculadas.');

        return self::SUCCESS;
    }
}
