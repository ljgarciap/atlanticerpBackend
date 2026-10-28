<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardProductDelivery;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-909 — modal de Pipeline "asignar productos a cada fecha de entrega parcial". Contrato
 * HTTP: `quote_item_id` como identificador de fila (nunca `catalog_product_id`, que puede
 * repetirse en 2 líneas de la misma cotización) y fechas siempre como STRING `YYYY-MM-DD` (nunca
 * el id interno `pipeline_card_delivery_date_id`) — ver docblock de ProductDeliveryService.
 */
class PipelineCardProductDeliveryControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{card: PipelineCard, quoteItemId: int} */
    private function makePartialCardWithQuoteItem(int $ownerId): array
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'proposal', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
            'delivery_type' => 'partial',
        ]);
        $card->deliveryDates()->create(['date' => '2026-11-01']);
        $card->deliveryDates()->create(['date' => '2026-12-15']);

        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto de prueba', 'description' => 'Producto de prueba',
            'price_full' => 250, 'cost' => 150, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        $quote = Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización Test', 'description' => 'Cotización Test', 'owner_id' => $card->owner_id,
            'status' => 'approved', 'folio' => 'COT-2026-0002', 'generated_at' => now(),
        ]);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $item = $part->items()->create([
            'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->description,
            'quantity' => 5, 'unit_price' => 250, 'cost' => 150,
        ]);

        return ['card' => $card, 'quoteItemId' => $item->id];
    }

    public function test_sin_acceso_a_la_tarjeta_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $otherId    = $this->userIdByEmail('management@atlantic.test');
        ['card' => $card] = $this->makePartialCardWithQuoteItem($otherId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments")
            ->assertStatus(403);
    }

    public function test_update_con_delivery_type_no_partial_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        $card->update(['delivery_type' => 'single']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments", [
                'assignments' => [['quote_item_id' => $quoteItemId, 'delivery_date' => '2026-11-01']],
            ])
            ->assertStatus(422);
    }

    public function test_get_devuelve_data_con_quote_item_id_como_identificador(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments")
            ->assertStatus(200);

        $response->assertJsonPath('data.0.quote_item_id', $quoteItemId);
        $response->assertJsonPath('data.0.quantity_ordered', 5);
        $response->assertJsonPath('data.0.assigned_delivery_date', null);
        // Sin stock (stock_quantity=0) -> disponible 0 < pedido 5 -> sugiere la fecha más lejana.
        $response->assertJsonPath('data.0.suggested_delivery_date', '2026-12-15');
    }

    public function test_put_persiste_asignacion_con_fecha_valida(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments", [
                'assignments' => [['quote_item_id' => $quoteItemId, 'delivery_date' => '2026-11-01']],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.0.assigned_delivery_date', '2026-11-01');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
    }

    public function test_put_con_fecha_no_definida_para_la_tarjeta_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments", [
                'assignments' => [['quote_item_id' => $quoteItemId, 'delivery_date' => '2027-01-01']],
            ])
            ->assertStatus(422);

        // El request HTTP resetea el tenant "current" al finalizar — volver a fijarlo antes
        // de usar el modelo Eloquent directo (mismo patrón que el resto de la suite).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
    }

    public function test_put_con_delivery_date_null_borra_la_asignacion_existente(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        $dateId = $card->deliveryDates()->where('date', '2026-11-01')->value('id');
        PipelineCardProductDelivery::create([
            'pipeline_card_id' => $card->id, 'quote_item_id' => $quoteItemId,
            'pipeline_card_delivery_date_id' => $dateId,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments", [
                'assignments' => [['quote_item_id' => $quoteItemId, 'delivery_date' => null]],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.0.assigned_delivery_date', null);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
    }

    // ── SCRUM-1027 — asignar productos dentro del flujo de edición (un solo PUT de la tarjeta) ──

    /** @return array<string, mixed> payload de edición de una tarjeta Parcial en Propuesta */
    private function editPayload(array $dates, ?array $productDeliveries = null): array
    {
        return [
            'delivery_type' => 'partial',
            'delivery_dates' => $dates,
            ...($productDeliveries === null ? [] : ['product_deliveries' => $productDeliveries]),
        ];
    }

    public function test_edicion_con_fechas_nuevas_sin_guardar_y_asignaciones_persiste_todo_en_un_solo_put(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // 2027-01-10 todavía no existe en BD: es una fecha del borrador de la edición.
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", $this->editPayload(
                ['2026-11-01', '2027-01-10'],
                [['quote_item_id' => $quoteItemId, 'delivery_date' => '2027-01-10']],
            ))
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $assignment = PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->firstOrFail();
        $this->assertSame('2027-01-10', $assignment->deliveryDate->date->toDateString());
    }

    public function test_guardar_la_tarjeta_con_las_mismas_fechas_no_borra_las_asignaciones_existentes(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        PipelineCardProductDelivery::create([
            'pipeline_card_id' => $card->id, 'quote_item_id' => $quoteItemId,
            'pipeline_card_delivery_date_id' => $card->deliveryDates()->where('date', '2026-11-01')->value('id'),
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                ...$this->editPayload(['2026-11-01', '2026-12-15']), 'observations' => 'solo cambia la nota',
            ])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
    }

    public function test_quitar_una_fecha_descarta_solo_las_asignaciones_de_esa_fecha(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        PipelineCardProductDelivery::create([
            'pipeline_card_id' => $card->id, 'quote_item_id' => $quoteItemId,
            'pipeline_card_delivery_date_id' => $card->deliveryDates()->where('date', '2026-12-15')->value('id'),
        ]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Se conserva 2026-11-01, se quita 2026-12-15 (la del producto) y se agrega 2027-02-01.
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", $this->editPayload(['2026-11-01', '2027-02-01']))
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
        $this->assertSame(['2026-11-01', '2027-02-01'], $card->deliveryDates()->orderBy('date')->get()
            ->map(fn ($d) => $d->date->toDateString())->all());
    }

    public function test_asignacion_a_fecha_fuera_del_borrador_retorna_422_y_no_persiste_nada(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", $this->editPayload(
                ['2026-11-01', '2027-01-10'],
                [['quote_item_id' => $quoteItemId, 'delivery_date' => '2030-05-05']],
            ))
            ->assertStatus(422);

        // Rollback completo: ni las asignaciones ni las fechas nuevas quedaron guardadas.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
        $this->assertSame(['2026-11-01', '2026-12-15'], $card->deliveryDates()->orderBy('date')->get()
            ->map(fn ($d) => $d->date->toDateString())->all());
    }

    public function test_product_deliveries_con_entrega_unica_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card, 'quoteItemId' => $quoteItemId] = $this->makePartialCardWithQuoteItem($designerId);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type' => 'single', 'delivery_dates' => ['2026-11-01'],
                'product_deliveries' => [['quote_item_id' => $quoteItemId, 'delivery_date' => '2026-11-01']],
            ])
            ->assertStatus(422);
    }

    public function test_get_con_dates_del_borrador_sugiere_la_fecha_mas_lejana_del_borrador(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        ['card' => $card] = $this->makePartialCardWithQuoteItem($designerId); // stock 0 < pedido 5
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments?dates[]=2026-11-01&dates[]=2027-03-20")
            ->assertStatus(200)
            ->assertJsonPath('data.0.suggested_delivery_date', '2027-03-20');
    }
}
