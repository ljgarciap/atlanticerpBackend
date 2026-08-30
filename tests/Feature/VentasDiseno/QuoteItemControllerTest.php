<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// Cotización-B (REQ-035/038/041/042) — ítems personalizados.
class QuoteItemControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{quote: Quote, part: QuotePart} */
    private function makeQuoteWithPart(int $ownerId): array
    {
        $quote = Quote::create(['owner_id' => $ownerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);

        return ['quote' => $quote, 'part' => $part];
    }

    private function validItemPayload(string $reference = 'REF-001'): array
    {
        return ['reference' => $reference, 'description' => 'Producto de prueba', 'quantity' => 2, 'unit_price' => 100];
    }

    private function makeCatalogProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'CAT-001', 'name' => 'Producto de catálogo', 'brand' => 'Test Brand',
            'price_full' => 200.00, 'cost' => 100.00, 'stock_quantity' => 5, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_crear_item_calcula_total_linea(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload())
            ->assertStatus(201);

        $response->assertJsonPath('reference', 'REF-001');

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $this->assertEquals(200.0, $detail->json('parts.0.items.0.line_total'));
        $this->assertEquals(200.0, $detail->json('parts.0.subtotal'));
        $this->assertEquals(200.0, $detail->json('subtotal'));
    }

    public function test_descuento_por_linea_afecta_el_total(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'discount_percent' => 10,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        // 2 * 100 * (1 - 0.10) = 180
        $this->assertEquals(180.0, $detail->json('parts.0.items.0.line_total'));
    }

    public function test_referencia_personalizada_duplicada_en_otra_partida_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $otherPart = $fixture['quote']->parts()->create(['name' => 'Partida 2']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload('ref-abc'))
            ->assertStatus(201);

        // REQ-038: se valida en TODA la cotización, no solo la misma partida —
        // y sin distinguir mayúsculas/minúsculas.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$otherPart->id}/items", $this->validItemPayload('REF-ABC'))
            ->assertStatus(422);
    }

    // ── margen mínimo (REQ-040, Pre-QA SCRUM-132) ───────────────────────────────

    public function test_crear_item_con_precio_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // unit_price=60, cost=50 -> margen (60-50)/60=16.7% (< 30% default).
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 60, 'cost' => 50,
            ])
            ->assertStatus(422);
    }

    public function test_actualizar_precio_que_viola_margen_retorna_422_y_no_persiste(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 100, 'cost' => 50,
            ])
            ->assertStatus(201);
        $itemId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}", [
                'reference' => 'REF-001', 'description' => 'Producto de prueba', 'quantity' => 2, 'unit_price' => 60, 'cost' => 50,
            ])
            ->assertStatus(422);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}");
        $this->assertEquals(100, $detail->json('parts.0.items.0.unit_price'));
    }

    public function test_descuento_de_linea_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // unit_price=100, cost=50 (margen sano 50%). 80% de descuento de línea -> precio 20, margen negativo.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 100, 'cost' => 50, 'discount_percent' => 80,
            ])
            ->assertStatus(422);
    }

    public function test_tipo_de_precio_individual_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // Público: (100-65)/100=35% ok. Override "project" (-15%): precio 85, margen (85-65)/85=23.5% (<30%).
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 100, 'cost' => 65, 'price_type_override' => 'project',
            ])
            ->assertStatus(422);
    }

    // ── SCRUM-725: margen global + excepción Mark/David + precio manual ────────

    public function test_agregar_item_ok_individualmente_pero_que_rompe_el_margen_global_con_descuento_de_totales_ya_aplicado_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create([
            'owner_id' => $designerId,
            'discount_totals_type' => 'percent', 'discount_totals_value' => 15,
        ]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // unit_price=100, cost=65 -> margen individual 35% (ok por sí solo), pero
        // con el 15% de descuento de Totales YA aplicado a la cotización, el
        // margen global cae a (85-65)/85=23.5% (<30%) — algo que el chequeo POR
        // ítem nunca ve, porque nunca mira discount_totals_*.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 100, 'cost' => 65,
            ])
            ->assertStatus(422);
    }

    public function test_mark_puede_crear_item_por_debajo_del_margen_sin_bloqueo(): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'mbekhar@illuminations.com.pa')
            ->update(['extra_permissions' => json_encode([
                'ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.override_min_margin',
            ])]);
        $markId = $this->userIdByEmail('mbekhar@illuminations.com.pa');
        $fixture = $this->makeQuoteWithPart($markId);
        // CoreUserSeeder: password default = el propio email (nunca sobreescrito si
        // ya se cambió), a diferencia de los usuarios *@illuminations.test de fixtures.
        $token = $this->loginAs('mbekhar@illuminations.com.pa', 'mbekhar@illuminations.com.pa', 'management');

        // Mismo escenario que test_crear_item_con_precio_que_viola_margen_retorna_422
        // (16.7% < 30%) pero con el permiso de excepción — no bloquea.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 60, 'cost' => 50,
            ])
            ->assertStatus(201)
            ->assertJsonPath('below_min_margin', true);
    }

    public function test_unit_price_override_en_producto_de_catalogo_no_modifica_el_precio_maestro(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct(['price_full' => 200, 'cost' => 100]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 1, 'unit_price_override' => 180,
            ])
            ->assertStatus(201)
            ->assertJsonPath('unit_price', 180);

        // El middleware de tenancy resetea el contexto al terminar el request HTTP
        // simulado de arriba — hay que reestablecerlo antes de volver a consultar.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(200.0, $product->fresh()->price_full, 'el precio maestro del catálogo no debe cambiar');
    }

    public function test_producto_personalizado_sin_costo_no_aplica_validacion_de_margen(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // Sin costo -> no hay referencia contra la cual medir margen (REQ-040 criterio 3),
        // incluso un precio absurdamente bajo debe pasar.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'unit_price' => 1,
            ])
            ->assertStatus(201);
    }

    public function test_editar_item_sin_cambiar_referencia_no_choca_consigo_mismo(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload())
            ->assertStatus(201);
        $itemId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}", [
                ...$this->validItemPayload(), 'quantity' => 5,
            ])
            ->assertStatus(200)
            ->assertJsonPath('quantity', 5);
    }

    public function test_borrar_item(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload())
            ->assertStatus(201);
        $itemId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}")
            ->assertStatus(204);
    }

    public function test_tipo_precio_override_no_acepta_partner(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'price_type_override' => 'partner',
            ])
            ->assertStatus(422);
    }

    // ── REQ-033: fórmulas de Tipo de Precio ─────────────────────────────────

    public function test_precio_proyecto_es_15_por_ciento_menos_que_publico(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['price_type' => 'project']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $this->assertEquals(85.0, $detail->json('parts.0.items.0.effective_unit_price'));
        $this->assertEquals(85.0, $detail->json('parts.0.items.0.line_total'));
    }

    public function test_precio_especial_es_5_por_ciento_adicional_sobre_proyecto(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['price_type' => 'special']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        // 100 * 0.85 * 0.95 = 80.75
        $this->assertEquals(80.75, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    public function test_tarifa_premium_es_costo_dividido_065(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['price_type' => 'premium']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100, 'cost' => 65,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $this->assertEquals(100.0, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    public function test_formula_de_proyecto_usa_el_porcentaje_configurado_en_pricing_settings(): void
    {
        // REQ-033 (2026-07-12): los porcentajes ya no son constantes PHP —
        // cambiar PricingSettings debe cambiar el cálculo real, sin deploy.
        PricingSettings::current()->update(['project_discount_percent' => 20]);

        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['price_type' => 'project']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        // 100 * (1 - 0.20) = 80, no 85 (el default de 15%).
        $this->assertEquals(80.0, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    public function test_precio_socio_es_costo_dividido_085(): void
    {
        $this->grantVentasDisenoEdit('management@illuminations.test');
        $managementId = $this->userIdByEmail('management@illuminations.test');
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('id', $managementId)
            ->update(['extra_permissions' => json_encode([
                'ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.select_partner_price',
            ])]);
        $fixture = $this->makeQuoteWithPart($managementId);
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}", ['price_type' => 'partner'])
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100, 'cost' => 85,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $this->assertEquals(100.0, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    public function test_tarifa_premium_sin_costo_cae_a_precio_tecleado(): void
    {
        // Sin costo cargado no se puede derivar Premium — no debe reventar el
        // cálculo del documento, se usa unit_price tal cual.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['price_type' => 'premium']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $this->assertEquals(100.0, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    // ── REQ-042: tipo de precio individual por ítem ──────────────────────────

    public function test_override_de_item_cambia_solo_ese_item(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        // Cotización en Público — el ítem con override "project" debe verse
        // afectado igual, sin depender del tipo de precio general.
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload('REF-A'), 'quantity' => 1, 'unit_price' => 100, 'price_type_override' => 'project',
            ])
            ->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload('REF-B'), 'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        $items = collect($detail->json('parts.0.items'))->keyBy('reference');
        $this->assertEquals(85.0, $items['REF-A']['effective_unit_price']);
        $this->assertEquals(100.0, $items['REF-B']['effective_unit_price']);
    }

    public function test_override_de_item_no_cambia_si_cambia_el_tipo_de_precio_general(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                ...$this->validItemPayload(), 'quantity' => 1, 'unit_price' => 100, 'price_type_override' => 'project',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}", ['price_type' => 'special'])
            ->assertStatus(200);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);

        // Sigue en 85 (fórmula de Proyecto) — el cambio a Especial en la
        // cotización no debe afectar a un ítem con su propio override.
        $this->assertEquals(85.0, $detail->json('parts.0.items.0.effective_unit_price'));
    }

    // ── REQ-035/036: producto de catálogo ────────────────────────────────────

    public function test_seleccionar_producto_de_catalogo_copia_datos_y_bloquea_descripcion(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct(['reference' => 'LAMP-050', 'name' => 'Lámpara colgante LED']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 2,
            ])
            ->assertStatus(201);

        // REQ-035: reference/description se autocompletan desde el catálogo.
        $response->assertJsonPath('reference', 'LAMP-050');
        $response->assertJsonPath('description', 'Lámpara colgante LED');
        $response->assertJsonPath('unit_price', 200);
        $response->assertJsonPath('is_custom', false);
        $response->assertJsonPath('catalog_product_id', $product->id);
    }

    public function test_item_de_catalogo_incluye_stock_quantity_en_la_respuesta(): void
    {
        // REQ-039: stock_quantity viaja en la respuesta del ítem (store, update y el
        // detalle de la cotización) para que el frontend compare contra `quantity`
        // sin pedir el producto aparte.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct(['reference' => 'STOCK-001', 'stock_quantity' => 5]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 8,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('stock_quantity', 5);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);
        $detail->assertJsonPath('parts.0.items.0.stock_quantity', 5);
        $detail->assertJsonPath('parts.0.items.0.quantity', 8);
    }

    public function test_item_personalizado_stock_quantity_es_null(): void
    {
        // REQ-039 AC implícito: un ítem sin catalog_product_id (personalizado)
        // no tiene ninguna fuente de stock — nunca debe disparar la alerta.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload())
            ->assertStatus(201);

        $response->assertJsonPath('stock_quantity', null);
    }

    public function test_seleccionar_producto_de_catalogo_ignora_precio_y_costo_mandados_por_el_cliente(): void
    {
        // Aunque el cliente mande unit_price/cost "falsos" junto con
        // catalog_product_id, el servidor los resuelve del producto real —
        // nunca confía en lo que venga del cliente para un ítem de catálogo.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct(['price_full' => 200, 'cost' => 100]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 1,
                'unit_price' => 1, 'cost' => 1, 'reference' => 'FAKE', 'description' => 'Fake',
            ])
            ->assertStatus(201)
            ->assertJsonPath('unit_price', 200)
            ->assertJsonPath('reference', 'CAT-001');
    }

    public function test_producto_de_catalogo_inexistente_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => 999999, 'quantity' => 1,
            ])
            ->assertStatus(422);
    }

    // ── REQ-038: producto de catálogo duplicado en la misma partida ─────────

    public function test_mismo_producto_de_catalogo_duplicado_en_la_misma_partida_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 1,
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 3,
            ])
            ->assertStatus(422);
    }

    public function test_mismo_producto_de_catalogo_en_otra_partida_no_choca(): void
    {
        // REQ-038: a diferencia de la referencia personalizada (toda la
        // cotización), el producto de catálogo se valida por PARTIDA — la
        // misma lámpara en "Sala" y en "Cocina" es un caso real, no un error.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $otherPart = $fixture['quote']->parts()->create(['name' => 'Partida 2']);
        $product = $this->makeCatalogProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 1,
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$otherPart->id}/items", [
                'catalog_product_id' => $product->id, 'quantity' => 1,
            ])
            ->assertStatus(201);
    }

    // ── POST .../items/bulk — REQ-037, alta en lote de una familia ────────────────

    public function test_bulk_agrega_todos_los_productos_sin_duplicar(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $p1 = $this->makeCatalogProduct(['reference' => 'BULK-001']);
        $p2 = $this->makeCatalogProduct(['reference' => 'BULK-002']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$p1->id, $p2->id],
            ])
            ->assertStatus(201);

        $this->assertCount(2, $response->json('created'));
        $this->assertCount(0, $response->json('skipped_catalog_product_ids'));

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}")
            ->assertStatus(200);
        $refs = collect($detail->json('parts.0.items'))->pluck('reference');
        $this->assertContains('BULK-001', $refs);
        $this->assertContains('BULK-002', $refs);
    }

    // SCRUM-725 — bulkStore() nunca validó margen (ni antes de este ticket): un
    // producto de catálogo con costo/precio propios ya por debajo del mínimo se
    // creaba igual. Mismo tratamiento que un duplicado: se saltea, no falla todo.
    public function test_bulk_saltea_productos_que_violan_el_margen_sin_fallar_el_resto(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        // (60-50)/60=16.7% < 30% default.
        $lowMargin = $this->makeCatalogProduct(['reference' => 'BULK-020', 'price_full' => 60, 'cost' => 50]);
        $healthy   = $this->makeCatalogProduct(['reference' => 'BULK-021', 'price_full' => 200, 'cost' => 100]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$lowMargin->id, $healthy->id],
            ])
            ->assertStatus(201);

        $this->assertCount(1, $response->json('created'));
        $this->assertSame('BULK-021', $response->json('created.0.reference'));
        $this->assertSame([$lowMargin->id], $response->json('skipped_catalog_product_ids'));
    }

    public function test_bulk_saltea_producto_que_viola_el_margen_global_aunque_sea_sano_individualmente(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = Quote::create([
            'owner_id' => $designerId,
            'discount_totals_type' => 'percent', 'discount_totals_value' => 15,
        ]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        // Individualmente 35% (ok), pero con el 15% de Totales ya aplicado el
        // margen global cae a 23.5% (mismo escenario que el test equivalente de store()).
        $product = $this->makeCatalogProduct(['reference' => 'BULK-030', 'price_full' => 100, 'cost' => 65]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/parts/{$part->id}/items/bulk", [
                'catalog_product_ids' => [$product->id],
            ])
            ->assertStatus(201);

        $this->assertCount(0, $response->json('created'));
        $this->assertSame([$product->id], $response->json('skipped_catalog_product_ids'));
    }

    public function test_bulk_saltea_por_margen_no_aplica_con_permiso_de_excepcion(): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'mbekhar@illuminations.com.pa')
            ->update(['extra_permissions' => json_encode([
                'ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.override_min_margin',
            ])]);
        $markId = $this->userIdByEmail('mbekhar@illuminations.com.pa');
        $fixture = $this->makeQuoteWithPart($markId);
        $lowMargin = $this->makeCatalogProduct(['reference' => 'BULK-040', 'price_full' => 60, 'cost' => 50]);
        $token = $this->loginAs('mbekhar@illuminations.com.pa', 'mbekhar@illuminations.com.pa', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$lowMargin->id],
            ])
            ->assertStatus(201);

        $this->assertCount(1, $response->json('created'));
        $this->assertCount(0, $response->json('skipped_catalog_product_ids'));
    }

    public function test_bulk_saltea_productos_ya_en_la_partida_sin_fallar_el_resto(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $existing = $this->makeCatalogProduct(['reference' => 'BULK-010']);
        $newOne   = $this->makeCatalogProduct(['reference' => 'BULK-011']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", [
                'catalog_product_id' => $existing->id, 'quantity' => 1,
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$existing->id, $newOne->id],
            ])
            ->assertStatus(201);

        $this->assertCount(1, $response->json('created'));
        $this->assertEquals([$existing->id], $response->json('skipped_catalog_product_ids'));
    }

    public function test_bulk_sin_permiso_edit_retorna_403(): void
    {
        // supervisor@ en vez de designer@ -- ver SCRUM-83. Sin ningún extra_permissions ni rol
        // mapeado a ventas_diseno, no alcanza ni lectura ni edición.
        $supervisorId = $this->userIdByEmail('supervisor@illuminations.test');
        $fixture = $this->makeQuoteWithPart($supervisorId);
        $product = $this->makeCatalogProduct();
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$product->id],
            ])
            ->assertStatus(403);
    }

    public function test_bulk_en_cotizacion_ajena_retorna_403(): void
    {
        // Senior Review 2026-07-13, sugerencia #4 — bulkStore() usa el mismo
        // canAccess() que store()/update(), pero no había un test dedicado que
        // lo probara (a diferencia de QuoteController/QuotePartController).
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct();

        $this->grantVentasDisenoEdit('supervisor@illuminations.test');
        $otherToken = $this->loginAs('supervisor@illuminations.test', 'Password123!', 'supervisor');

        $this->withHeaders($this->authHeaders($otherToken))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$product->id],
            ])
            ->assertStatus(403);
    }

    public function test_bulk_con_producto_inexistente_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [999999],
            ])
            ->assertStatus(422);
    }

    public function test_bulk_items_creados_traen_cantidad_uno_por_defecto(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct(['reference' => 'BULK-020']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$product->id],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('created.0.quantity', 1);
        $response->assertJsonPath('created.0.reference', 'BULK-020');
    }

    // ── SCRUM-734 (RN9.1): preview-price — validación de margen en vivo, sin persistir ─

    public function test_preview_price_sin_violar_margen_no_marca_nada(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $itemId = $fixture['part']->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 50,
        ])->id;
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}/preview-price", [
                'unit_price' => 100,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('violates_margin', false);
        $response->assertJsonPath('message', null);
    }

    public function test_preview_price_violando_margen_marca_y_no_persiste(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $itemId = $fixture['part']->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 50,
        ])->id;
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        // costo 50, precio 60 -> margen 16.7%, por debajo del 30% default.
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}/preview-price", [
                'unit_price' => 60,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('violates_margin', true);
        $this->assertNotNull($response->json('message'));
        $response->assertJsonPath('can_override', false);

        // No persiste nada — el precio real del ítem sigue siendo el original.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertEquals(100, QuoteItem::find($itemId)?->unit_price);
    }

    public function test_preview_price_no_expone_el_costo_real(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $itemId = $fixture['part']->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 50,
        ])->id;
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}/preview-price", [
                'unit_price' => 60,
            ])
            ->assertStatus(200);

        $this->assertArrayNotHasKey('cost', $response->json());
    }

    public function test_preview_price_con_permiso_de_excepcion_marca_can_override(): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'mbekhar@illuminations.com.pa')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.override_min_margin'])]);
        $markId = $this->userIdByEmail('mbekhar@illuminations.com.pa');
        $fixture = $this->makeQuoteWithPart($markId);
        $itemId = $fixture['part']->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 50,
        ])->id;
        $token = $this->loginAs('mbekhar@illuminations.com.pa', 'mbekhar@illuminations.com.pa', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$itemId}/preview-price", [
                'unit_price' => 60,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('violates_margin', true);
        $response->assertJsonPath('can_override', true);
    }

    public function test_preview_price_item_inexistente_retorna_404(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/999999/preview-price", [
                'unit_price' => 60,
            ])
            ->assertStatus(404);
    }

    // ── SCRUM-734 (RN2.2, Senior Review retroactivo 2026-08-06) ─────────────────
    // Antes de este fix, store/update/bulkStore/destroy nunca chequeaban
    // confirmed_at — una cotización "confirmada e inmutable" se podía editar
    // completo por acá, sin ningún bloqueo (200/201/204 en vez de 422).

    public function test_crear_item_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $fixture['quote']->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items", $this->validItemPayload())
            ->assertStatus(422);
    }

    public function test_editar_item_en_cotizacion_confirmada_retorna_422_y_no_persiste(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $item = $fixture['part']->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);
        $fixture['quote']->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson(
                "/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$item->id}",
                ['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 999],
            )
            ->assertStatus(422);

        $this->assertStringContainsString('no se puede editar directamente', (string) $response->json('message'));
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertEquals(100, $item->fresh()?->unit_price);
    }

    public function test_bulk_store_en_cotizacion_confirmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $product = $this->makeCatalogProduct();
        $fixture['quote']->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/bulk", [
                'catalog_product_ids' => [$product->id],
            ])
            ->assertStatus(422);
    }

    public function test_borrar_item_en_cotizacion_confirmada_retorna_422_y_no_borra(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $item = $fixture['part']->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);
        $fixture['quote']->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$item->id}")
            ->assertStatus(422);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertNotNull($item->fresh());
    }

    public function test_preview_price_sigue_funcionando_en_cotizacion_confirmada(): void
    {
        // previewPrice() nunca persiste — no hace falta bloquearlo, y el frontend
        // podría querer mostrar el precio de una versión histórica de solo lectura.
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $fixture = $this->makeQuoteWithPart($designerId);
        $item = $fixture['part']->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);
        $fixture['quote']->update(['folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$fixture['quote']->id}/parts/{$fixture['part']->id}/items/{$item->id}/preview-price", [
                'unit_price' => 100,
            ])
            ->assertStatus(200);
    }
}
