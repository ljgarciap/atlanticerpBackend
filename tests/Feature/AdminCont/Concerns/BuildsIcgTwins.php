<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concerns;

use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Support\Facades\DB;

/** Fixture de un pedido gemelo de ICG: cotización gemela con un ítem, SubCliente propio y pedido sin tarjeta. Requiere `BuildsPartiallyInvoicedOrders`. */
trait BuildsIcgTwins
{
    /** @param array<string, mixed> $subAttrs */
    private function gemelo(array $subAttrs = [], string $stage = Order::STAGE_ENTREGADO): Order
    {
        $master = MasterClient::create(['name' => 'Cliente gemelo '.uniqid()]);
        $sub    = SubClient::create($subAttrs + [
            'master_client_id' => $master->id, 'business_name' => 'CONSTRUCTORA GRUPO V, SA', 'tax_id' => '1689571-1-684168', 'tax_id_dv' => '33',
        ]);

        $quoteId = DB::table((new Quote())->getTable())->insertGetId([
            'origin' => 'icg_legacy', 'owner_id' => $this->ownerId(), 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'folio' => 'ICG-V11P-'.self::uniqueTestNumber(), 'generated_at' => now(), 'status' => 'draft', 'price_type' => 'public',
            'discount_mode' => 'line', 'global_discount_percent' => 0, 'discount_totals_type' => 'percent', 'discount_totals_value' => 0,
            'includes_installation' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $part = QuotePart::create(['quote_id' => $quoteId, 'name' => 'Pedido ICG', 'position' => 1]);
        $item = $part->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'ICG-1', 'description' => 'Saldo pendiente',
            'quantity' => 4, 'unit_price' => 100.00, 'discount_percent' => 0,
        ]);

        $order = Order::create([
            'order_number' => 'ICG-V11P-'.self::uniqueTestNumber(), 'origin' => 'icg_legacy', 'icg_serie' => 'V11P', 'icg_numero' => self::uniqueTestNumber(),
            'icg_facturado' => 0, 'icg_total_neto' => 428.00, 'quote_id' => $quoteId, 'sub_client_id' => $sub->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => $stage, 'customer_name' => $sub->business_name,
        ]);
        $order->items()->create(['quote_item_id' => $item->id, 'description_snapshot' => 'Saldo pendiente', 'qty_requested' => 4, 'qty_picked' => 4, 'qty_delivered' => 4]);

        return $order->fresh() ?? $order;
    }
}
