<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

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
 * SCRUM-929 (B1a) — cubre `bodega:backfill-order-item-quote-links`, el intento retroactivo de
 * enlazar `order_items.quote_item_id` para pedidos creados antes del vínculo. Es best-effort (sin FK
 * previa): el emparejamiento por producto solo se aplica cuando es ÚNICO; con 0 o 2+ candidatos se
 * omite a propósito y ese pedido queda solo con facturación "completo" (ver ADR-SCRUM929).
 */
class BodegaBackfillOrderItemQuoteLinksCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{card: PipelineCard, quote: Quote} */
    private function makeCardWithGeneratedQuote(): array
    {
        $ownerId = $this->userIdByEmail('management@atlantic.test');
        $master  = MasterClient::create(['name' => 'Cliente Links '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => '155-0000-1-'.self::uniqueTestNumber()]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
        $quote = Quote::create([
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización Links', 'description' => 'Cotización Links', 'owner_id' => $ownerId,
            'status' => 'approved', 'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => now(),
        ]);

        return ['card' => $card, 'quote' => $quote];
    }

    private function makeOrder(PipelineCard $card): Order
    {
        return Order::create([
            'order_number'     => 'PED-2026-'.self::uniqueTestNumber(),
            'order_type'       => Order::ORDER_TYPE_PEDIDO,
            'stage'            => Order::STAGE_ASIGNADO,
            'customer_name'    => 'Cliente de prueba',
            'pipeline_card_id' => $card->id,
        ]);
    }

    private function makeProduct(string $name): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => $name, 'description' => $name,
            'price_full' => 200, 'cost' => 120, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    public function test_vincula_el_order_item_y_rellena_unit_price_nulo_cuando_el_match_es_unico(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto vínculo');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $qi      = $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 4, 'unit_price' => 200,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 4,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($qi->id, $item->fresh()->quote_item_id);
        $this->assertSame(200.0, $item->fresh()->unit_price, 'Un unit_price nulo se rellena de paso.');
    }

    public function test_vincula_y_no_toca_un_unit_price_que_ya_existe_y_coincide_con_la_cotizacion(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto precio propio');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $qi      = $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 1, 'unit_price' => 200,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1, 'unit_price' => 200.00,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($qi->id, $item->fresh()->quote_item_id);
        $this->assertSame(200.0, $item->fresh()->unit_price);
    }

    /** Senior Review MEDIO-1 — el precio ya snapshoteado es una prueba de que el candidato es OTRO ítem. */
    public function test_omite_si_el_unit_price_ya_snapshoteado_no_coincide_con_la_cotizacion(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto precio distinto');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 1, 'unit_price' => 200,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1, 'unit_price' => 155.55,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id, 'Un precio que no cuadra indica otra versión: no se adivina.');
        $this->assertSame(155.55, $item->fresh()->unit_price, 'El snapshot existente nunca se pisa.');
    }

    public function test_omite_si_la_cantidad_pedida_supera_la_cotizada(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto cantidad');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 4, 'unit_price' => 200,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 10,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id);
    }

    /**
     * Senior Review MEDIO-1 (reproducción) — 2 cotizaciones con folio en el mismo proyecto; la 2ª se
     * generó DESPUÉS del pedido y trae otra cantidad y otro precio. El ítem debe enlazar a la
     * versión vigente cuando se creó el pedido (v1), no a la más reciente (v2). Con la lógica
     * anterior (`resolveApprovedQuote()`) este test enlazaba a v2.
     */
    public function test_usa_la_version_de_cotizacion_vigente_al_crear_el_pedido_no_la_mas_reciente(): void
    {
        ['card' => $card, 'quote' => $v1] = $this->makeCardWithGeneratedQuote();
        $v1->update(['generated_at' => now()->subDay()]);
        $product = $this->makeProduct('Producto versionada');

        $itemV1 = QuotePart::create(['quote_id' => $v1->id, 'name' => 'Partida v1', 'position' => 1])->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 4, 'unit_price' => 200,
        ]);

        $order = $this->makeOrder($card);

        $v2 = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización v2', 'description' => 'Cotización v2', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => now()->addDay(),
        ]);
        $itemV2 = QuotePart::create(['quote_id' => $v2->id, 'name' => 'Partida v2', 'position' => 1])->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 9, 'unit_price' => 999,
        ]);

        $item = $order->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 4,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($itemV1->id, $item->fresh()->quote_item_id, 'Debe enlazar a la versión vigente al crear el pedido.');
        $this->assertNotSame($itemV2->id, $item->fresh()->quote_item_id);
        $this->assertSame(200.0, $item->fresh()->unit_price, 'El precio rellenado es el de v1, no el de v2 (999).');
    }

    public function test_omite_si_ninguna_cotizacion_estaba_generada_al_crear_el_pedido(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $quote->update(['generated_at' => now()->addDay()]); // "generada" en el futuro respecto del pedido
        $product = $this->makeProduct('Producto sin cotización vigente');
        QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1])->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 1, 'unit_price' => 200,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id);
    }

    /**
     * Las tarjetas derivadas (Faltante, Revisión) nacen días después del pedido: la cotización se
     * resuelve con la fecha de la RAÍZ de la familia, no con la de la tarjeta del ítem.
     */
    public function test_una_tarjeta_derivada_usa_la_fecha_de_la_raiz_de_su_familia(): void
    {
        ['card' => $card, 'quote' => $v1] = $this->makeCardWithGeneratedQuote();
        $v1->update(['generated_at' => now()->subDays(2)]);
        $product = $this->makeProduct('Producto faltante');
        $itemV1  = QuotePart::create(['quote_id' => $v1->id, 'name' => 'Partida v1', 'position' => 1])->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 4, 'unit_price' => 200,
        ]);

        $root = $this->makeOrder($card);
        $root->forceFill(['created_at' => now()->subDay()])->save();

        // v2 se genera entre la raíz y la tarjeta derivada.
        $v2 = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización v2', 'description' => 'Cotización v2', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => now()->subHours(12),
        ]);
        QuotePart::create(['quote_id' => $v2->id, 'name' => 'Partida v2', 'position' => 1])->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 4, 'unit_price' => 350,
        ]);

        $faltante = Order::create([
            'order_number' => $root->order_number, 'order_type' => Order::ORDER_TYPE_FALTANTE,
            'stage' => Order::STAGE_ASIGNADO, 'customer_name' => 'Cliente de prueba',
            'pipeline_card_id' => $card->id, 'family_id' => $root->id,
        ]);
        $item = $faltante->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 2,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($itemV1->id, $item->fresh()->quote_item_id, 'Con la fecha de la tarjeta derivada habría enlazado a v2.');
        $this->assertSame(200.0, $item->fresh()->unit_price);
    }

    public function test_vincula_un_item_personalizado_por_reference(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $qi   = $part->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'CUSTOM-LINK',
            'name' => 'Personalizado', 'description' => 'Personalizado', 'quantity' => 1, 'unit_price' => 30,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => null, 'qty_requested' => 1,
            'reference_snapshot' => 'CUSTOM-LINK', 'description_snapshot' => 'Personalizado',
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($qi->id, $item->fresh()->quote_item_id);
    }

    public function test_omite_el_item_cuando_hay_2_quote_items_candidatos_del_mismo_producto(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto ambigua');
        foreach ([['A', 1, 2, 200], ['B', 2, 3, 180]] as [$name, $pos, $qty, $price]) {
            QuotePart::create(['quote_id' => $quote->id, 'name' => "Partida {$name}", 'position' => $pos])
                ->items()->create([
                    'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
                    'description' => $product->description, 'quantity' => $qty, 'unit_price' => $price,
                ]);
        }

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 5,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id, 'Adivinar un vínculo podría cobrar de más o de menos.');
        $this->assertNull($item->fresh()->unit_price);
    }

    public function test_omite_el_item_cuando_el_pedido_no_tiene_cotizacion_resoluble(): void
    {
        $order = Order::create([
            'order_number' => 'PED-2026-'.self::uniqueTestNumber(), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_ASIGNADO, 'customer_name' => 'Sin tarjeta',
        ]);
        $item = $order->items()->create(['qty_requested' => 1, 'reference_snapshot' => 'X', 'description_snapshot' => 'X']);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id);
    }

    public function test_no_reprocesa_items_ya_vinculados(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto ya vinculada');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 1, 'unit_price' => 200,
        ]);

        $yaVinculado = self::uniqueTestNumber();
        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1, 'quote_item_id' => $yaVinculado,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($yaVinculado, $item->fresh()->quote_item_id, 'Idempotente: nunca reescribe un vínculo existente.');
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->makeCardWithGeneratedQuote();
        $product = $this->makeProduct('Producto dry-run');
        $part    = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false, 'reference' => $product->reference,
            'description' => $product->description, 'quantity' => 1, 'unit_price' => 90,
        ]);

        $item = $this->makeOrder($card)->items()->create([
            'catalog_product_id' => $product->id, 'qty_requested' => 1,
            'reference_snapshot' => $product->reference, 'description_snapshot' => $product->description,
        ]);

        $this->artisan('bodega:backfill-order-item-quote-links', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($item->fresh()->quote_item_id);
        $this->assertNull($item->fresh()->unit_price);
    }
}
