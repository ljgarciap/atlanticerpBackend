<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\Compras\Services\GoodsReceiptConfirmationService;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-719 — comando de un solo uso para sembrar datos de QA en dev.atlanticerp.ai: crea, vía los
 * modelos y el servicio reales de Compras (no SQL directo), una PurchaseOrder + un Ingreso de
 * Mercancía CONFIRMADO por Pedido elegido, para que `OrdersService::resolveInvoicedCosts()`
 * (app/Modules/VentasDiseno/Services/OrdersService.php) tenga costo facturado real que cruzar
 * contra el costo cotizado y así ejercitar los 3 estados de margen facturado de la pantalla
 * "Pedidos" (SCRUM-703→710) que hoy no se pueden validar porque los 17 Pedidos de dev están
 * 100% en "Pendiente de compra".
 *
 * Un solo producto por Pedido queda facturado a propósito — el resto de los ítems de cada
 * cotización se deja "pendiente" (no se crea línea de compra para ellos), que es justamente el
 * escenario mixto real que el mockup espera ver en Pedidos. Cada Pedido usa una PurchaseOrder
 * dedicada con una sola línea, así que no hay riesgo de la ambigüedad RN6 de
 * `resolveInvoicedCosts()` (mismo purchase_order_id+catalog_product_id repartido entre >1
 * sales_project_id).
 *
 * Descartable una vez que QA confirme los 3 estados — no forma parte del flujo de negocio.
 */
final class QaSeedInvoicedComprasCommand extends Command
{
    protected $signature = 'qa:seed-invoiced-compras';

    protected $description = 'SCRUM-719 — siembra costo facturado real (vía Compras) para 3 Pedidos, uno por cada estado de margen facturado';

    public function handle(): int
    {
        $tenant = Tenant::query()->first();
        if ($tenant === null) {
            $this->error('No hay tenant configurado.');

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $provider = Provider::where('origin', Provider::ORIGIN_INTERNACIONAL)->first();
        $warehouse = Warehouse::first();
        $user = User::where('email', 'like', '%atlantic.com.pa')->orderBy('id')->first() ?? User::first();

        if ($provider === null || $warehouse === null || $user === null) {
            $this->error('Faltan datos base (provider/warehouse/user) para sembrar.');

            return self::FAILURE;
        }

        /**
         * Primer batch (Pedidos multi-ítem 124/127/131): factura UN SOLO producto de cada
         * cotización, deja el resto pendiente — cubre el caso mixto real. Segundo batch (140/115/
         * 128): cotizaciones de 1 solo ítem, facturado completo — necesario para que
         * `all_invoiced` sea true en al menos un Pedido por estado y "Margen facturado promedio"
         * (`OrdersService::summary()`, que solo promedia Pedidos 100% facturados) deje de ser
         * null. Ambos batches se corrieron en la misma sesión (2026-08-01) — ver memoria SCRUM-719.
         *
         * @var list<array{label: string, quote_id: int, project_id: int, product_id: int, invoiced_cost: float}>
         */
        $scenarios = [
            ['label' => 'Sin variación', 'quote_id' => 124, 'project_id' => 98,  'product_id' => 5,  'invoiced_cost' => 32.0],
            ['label' => 'Margen bajo',   'quote_id' => 127, 'project_id' => 104, 'product_id' => 11, 'invoiced_cost' => 130.0],
            ['label' => 'Margen mejoró', 'quote_id' => 131, 'project_id' => 108, 'product_id' => 3,  'invoiced_cost' => 6.0],
            ['label' => 'Sin variación (100% facturado)', 'quote_id' => 140, 'project_id' => 122, 'product_id' => 5,  'invoiced_cost' => 32.0],
            ['label' => 'Margen bajo (100% facturado)',   'quote_id' => 115, 'project_id' => 87,  'product_id' => 5,  'invoiced_cost' => 45.0],
            ['label' => 'Margen mejoró (100% facturado)', 'quote_id' => 128, 'project_id' => 105, 'product_id' => 12, 'invoiced_cost' => 8.0],
        ];

        foreach ($scenarios as $scenario) {
            // Idempotencia: si ya existe una PurchaseOrderLine para este Pedido+producto (corrida
            // previa del mismo comando), no duplicar — cada re-corrida solo siembra lo que falta.
            $alreadySeeded = PurchaseOrderLine::where('sales_project_id', $scenario['project_id'])
                ->where('catalog_product_id', $scenario['product_id'])
                ->exists();
            if ($alreadySeeded) {
                $this->line("Pedido/Quote {$scenario['quote_id']} (proyecto {$scenario['project_id']}, producto {$scenario['product_id']}) ya tiene compra sembrada — omitido.");

                continue;
            }

            $quote = Quote::find($scenario['quote_id']);
            if ($quote === null) {
                $this->error("Quote {$scenario['quote_id']} no existe — abortando.");

                return self::FAILURE;
            }

            $item = QuoteItem::where('catalog_product_id', $scenario['product_id'])
                ->whereHas('quotePart', fn ($q) => $q->where('quote_id', $quote->id))
                ->first();
            if ($item === null) {
                $this->error("QuoteItem con catalog_product_id={$scenario['product_id']} no encontrado en Quote {$quote->id} — abortando.");

                return self::FAILURE;
            }

            DB::transaction(function () use ($scenario, $provider, $warehouse, $user, $item): void {
                $order = PurchaseOrder::create([
                    'provider_id'            => $provider->id,
                    'status'                 => PurchaseOrder::STATUS_RECIBIDO,
                    'status_changed_at'      => now(),
                    'shipping_type'          => PurchaseOrder::SHIPPING_MARITIMO,
                    'who_pays_shipping'      => PurchaseOrder::PAYS_PROVIDER,
                    'modality'               => PurchaseOrder::MODALITY_DIRECTO,
                    'requires_mark_approval' => false,
                    'currency'               => 'USD',
                    'notes'                  => "SCRUM-719 QA seed — {$scenario['label']}",
                    'created_by'             => $user->id,
                ]);

                $subtotal = $scenario['invoiced_cost'] * $item->quantity;

                PurchaseOrderLine::create([
                    'purchase_order_id'  => $order->id,
                    'catalog_product_id' => $scenario['product_id'],
                    'quantity'           => $item->quantity,
                    'unit_cost'          => $scenario['invoiced_cost'],
                    'subtotal'           => $subtotal,
                    'sales_project_id'   => $scenario['project_id'],
                ]);

                $order->update(['total_amount' => $subtotal]);

                $receipt = PurchaseOrderReceipt::create([
                    'purchase_order_id' => $order->id,
                    'created_by'        => $user->id,
                ]);

                $receiptLine = PurchaseOrderReceiptLine::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'catalog_product_id'        => $scenario['product_id'],
                    'quantity'                  => $item->quantity,
                    'unit_cost'                 => $scenario['invoiced_cost'],
                    'warehouse_id'               => $warehouse->id,
                    'confirmed_at'               => now(),
                ]);

                app(GoodsReceiptConfirmationService::class)->confirmLine($receiptLine, $user->id);

                $this->info(
                    "Pedido/Quote {$scenario['quote_id']} (proyecto {$scenario['project_id']}) — "
                    . "{$scenario['label']}: PO #{$order->id}, producto {$scenario['product_id']}, "
                    . "costo cotizado={$item->cost}, costo facturado={$scenario['invoiced_cost']}"
                );
            });
        }

        $this->info('Listo — verificar GET /pedidos en dev.atlanticerp.ai.');

        return self::SUCCESS;
    }
}
