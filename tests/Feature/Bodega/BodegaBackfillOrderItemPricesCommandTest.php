<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-786 — cubre `bodega:backfill-order-item-prices`, el intento retroactivo de rellenar
 * `unit_price` para pedidos creados antes de `OrderService::createItemsFromQuote()` snapshotear
 * el precio. El emparejamiento Order→PipelineCard→Quote→QuoteItem es best-effort (sin FK) — estos
 * tests cubren tanto el caso feliz (1 solo candidato, se backfillea) como el ambiguo (2+
 * candidatos, se omite a propósito).
 */
class BodegaBackfillOrderItemPricesCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{card: PipelineCard, quote: Quote} */
    private function makeCardWithGeneratedQuote(): array
    {
        $ownerId = $this->userIdByEmail('management@illuminations.test');
        $master  = MasterClient::create(['name' => 'Cliente Backfill '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
        $quote = Quote::create([
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización Backfill', 'description' => 'Cotización Backfill', 'owner_id' => $ownerId,
            'status' => 'approved', 'folio' => 'COT-2026-'.random_int(1000, 9999), 'generated_at' => now(),
        ]);

        return ['card' => $card, 'quote' => $quote];
    }

    private function makeOrder(PipelineCard $card): Order
    {
        return Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'order_type'   => Order::ORDER_TYPE_PEDIDO,
            'stage'        => Order::STAGE_ASIGNADO,
            'customer_name' => 'Cliente de prueba',
            'pipeline_card_id' => $card->id,
        ]);
    }

    public function test_backfillea_order_item_y_propaga_a_customer_return_line_cuando_el_match_es_unico(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Lámpara de prueba', 'description' => 'Lámpara de prueba',
            'price_full' => 200, 'cost' => 120, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 4, 'unit_price' => 200,
        ]);

        $order = $this->makeOrder($card);
        $item = $order->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 4, 'qty_delivered' => 4,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);
        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.random_int(1000, 999999), 'order_id' => $order->id,
            'status' => CustomerReturn::STATUS_PENDIENTE, 'contact_name' => 'Ana', 'contact_phone' => '6000-0000',
            'created_by' => $this->userIdByEmail('management@illuminations.test'),
        ]);
        $line = $customerReturn->lines()->create([
            'order_item_id' => $item->id, 'reference_snapshot' => $item->reference_snapshot,
            'description_snapshot' => $item->description_snapshot, 'qty_requested' => 2, 'reason' => 'excedente',
        ]);

        $this->artisan('bodega:backfill-order-item-prices', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame(200.0, $item->fresh()->unit_price);
        $this->assertSame(200.0, $line->fresh()->unit_price);
    }

    public function test_omite_order_item_cuando_hay_2_quote_items_candidatos_del_mismo_producto(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Lámpara ambigua', 'description' => 'Lámpara ambigua',
            'price_full' => 200, 'cost' => 120, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        // Mismo catalog_product_id en 2 partidas distintas — emparejamiento imposible sin FK real.
        $partA = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida A', 'position' => 1]);
        $partA->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 2, 'unit_price' => 200,
        ]);
        $partB = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida B', 'position' => 2]);
        $partB->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 3, 'unit_price' => 180,
        ]);

        $order = $this->makeOrder($card);
        $item = $order->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 5, 'qty_delivered' => 5,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-prices', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->unit_price);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Lámpara dry-run', 'description' => 'Lámpara dry-run',
            'price_full' => 90, 'cost' => 50, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 1, 'unit_price' => 90,
        ]);

        $order = $this->makeOrder($card);
        $item = $order->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1, 'qty_delivered' => 1,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-prices', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->unit_price);
    }
}
