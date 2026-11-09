<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\LiquidationAgency;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Fase 29b — Compras: estados claros, proveedores/agencias, cantidades mínimas, familias y documentos. */
class Fase29bComprasTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grant(array $perms): void
    {
        DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @return array<string, string> */
    private function h(): array
    {
        $headers = $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $headers;
    }

    private function provider(string $name = 'Proveedor 29b'): Provider
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // tras una petición HTTP el tenant queda olvidado
        return Provider::create(['name' => $name.' '.uniqid(), 'currency' => 'USD', 'category' => 'locales', 'country' => 'Panamá']);
    }

    private function product(array $o = []): CatalogProduct
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        return CatalogProduct::create([
            'reference' => 'P29B-'.uniqid(), 'name' => 'Producto '.uniqid(), 'description' => 'x', 'price_full' => 20, 'cost' => 10,
            'stock_quantity' => 0, 'reorder_point' => 0, 'is_active' => true, ...$o,
        ]);
    }

    private function reMake(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── Grupo 4 ─────────────────────────────────────────────────────────────────

    public function test_orden_aprobada_se_muestra_aprobada_sin_cambiar_el_estado_real(): void
    {
        $this->grant(['compras.read']);
        $p = $this->provider();
        $pendiente = PurchaseOrder::create(['provider_id' => $p->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now(), 'total_amount' => 10, 'requires_primary_approval' => true]);
        $aprobada = PurchaseOrder::create(['provider_id' => $p->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now(), 'total_amount' => 10, 'requires_primary_approval' => true, 'approved_by' => 1, 'approved_at' => now()]);

        $this->withHeaders($this->h())->getJson("/api/compras/orders/{$pendiente->id}")->assertJsonPath('status', 'por_aprobar')->assertJsonPath('status_visible', 'por_aprobar');
        $this->withHeaders($this->h())->getJson("/api/compras/orders/{$aprobada->id}")->assertJsonPath('status', 'por_aprobar')->assertJsonPath('status_visible', 'aprobada');
    }

    // ── Grupo 5 ─────────────────────────────────────────────────────────────────

    public function test_agencia_con_nombre_repetido_da_422_y_se_puede_renombrar_y_corregir_el_contacto(): void
    {
        $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        $this->withHeaders($this->h())->postJson('/api/compras/liquidation-agencies', ['name' => 'Agencia Uno'])->assertStatus(201);
        $this->withHeaders($this->h())->postJson('/api/compras/liquidation-agencies', ['name' => 'agencia uno'])->assertStatus(422)->assertJsonValidationErrors('name');
        $dos = $this->withHeaders($this->h())->postJson('/api/compras/liquidation-agencies', ['name' => 'Agencia Dos'])->json('id');

        $this->withHeaders($this->h())->putJson("/api/compras/liquidation-agencies/{$dos}", ['name' => 'AGENCIA UNO'])->assertStatus(422);
        $this->withHeaders($this->h())->putJson("/api/compras/liquidation-agencies/{$dos}", ['name' => 'Agencia Dos B', 'contact_name' => 'Ana Prueba', 'email' => 'ana@example.com'])
            ->assertStatus(200)->assertJsonPath('name', 'Agencia Dos B')->assertJsonPath('contact_name', 'Ana Prueba');
    }

    public function test_agencia_desactivada_no_se_ofrece_ni_se_puede_asignar_pero_se_conserva(): void
    {
        $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $a = LiquidationAgency::create(['name' => 'Agencia Baja '.uniqid()]);
        $this->withHeaders($this->h())->putJson("/api/compras/liquidation-agencies/{$a->id}", ['is_active' => false])->assertStatus(200)->assertJsonPath('is_active', false);

        $lista = $this->withHeaders($this->h())->getJson('/api/compras/liquidation-agencies?search=Baja')->json('data');
        $this->assertCount(0, $lista);
        $this->withHeaders($this->h())->getJson('/api/compras/liquidation-agencies?search=Baja&incluir_inactivas=1')->assertJsonCount(1, 'data');

        $this->reMake();
        $int = Provider::create(['name' => 'Prov Int '.uniqid(), 'currency' => 'USD', 'category' => 'china', 'origin' => Provider::ORIGIN_INTERNACIONAL, 'country' => 'China']);
        $o = PurchaseOrder::create(['provider_id' => $int->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR, 'status_changed_at' => now(), 'total_amount' => 5]);
        $this->withHeaders($this->h())->patchJson("/api/compras/orders/{$o->id}/liquidate", ['agency_id' => $a->id])->assertStatus(422);
        $this->assertDatabaseHas('atlantic_compras.liquidation_agencies', ['id' => $a->id]);
    }

    public function test_proveedor_edicion_parcial_desactivar_y_control_de_version(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        $p = $this->provider('Prov Parcial');
        $v = $this->withHeaders($this->h())->getJson("/api/compras/providers/{$p->id}")->json('version');
        $this->assertNotEmpty($v);

        // PATCH solo con lo enviado (sin reenviar nombre ni categoría). El reloj avanza para que la versión cambie (columna al segundo).
        $this->travel(2)->seconds();
        $r = $this->withHeaders($this->h())->patchJson("/api/compras/providers/{$p->id}", ['contact_name' => 'Contacto Nuevo', 'version' => $v])->assertStatus(200);
        $r->assertJsonPath('contact_name', 'Contacto Nuevo')->assertJsonPath('name', $p->name);

        // Con la versión vieja, otra persona ya cambió: 409 y no pisa nada.
        $this->withHeaders($this->h())->patchJson("/api/compras/providers/{$p->id}", ['contact_name' => 'Otro', 'version' => $v])->assertStatus(409)->assertJsonPath('code', 'provider_changed');
        $this->assertSame('Contacto Nuevo', (function () use ($p) { Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); return Provider::find($p->id)?->contact_name; })());

        // Desactivar: sale del listado, se ve con el filtro y la fila se conserva.
        $this->withHeaders($this->h())->patchJson("/api/compras/providers/{$p->id}", ['is_active' => false])->assertStatus(200)->assertJsonPath('is_active', false);
        $ids = collect($this->withHeaders($this->h())->getJson('/api/compras/providers?per_page=100')->json('data'))->pluck('id');
        $this->assertNotContains($p->id, $ids->all());
        $ids = collect($this->withHeaders($this->h())->getJson('/api/compras/providers?per_page=100&incluir_inactivas=1')->json('data'))->pluck('id');
        $this->assertContains($p->id, $ids->all());
    }

    public function test_put_completo_sigue_exigiendo_el_nombre(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        $p = $this->provider();
        $this->withHeaders($this->h())->putJson("/api/compras/providers/{$p->id}", ['contact_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_cantidad_minima_y_multiplo_ajustan_la_sugerencia_y_son_opcionales(): void
    {
        $this->grant(['compras.read', 'compras.write', 'compras.edit']);
        $prov = $this->provider();
        $prod = $this->product(['provider_id' => $prov->id]);
        $prod->syncProviders([$prov->id], $prov->id);

        $this->assertSame(7, $prod->fresh()->cantidadSugerida(7), 'sin condiciones compra lo que falta');

        $this->withHeaders($this->h())->putJson("/api/compras/replenishment/products/{$prod->id}/purchase-terms", ['provider_id' => $prov->id, 'min_order_quantity' => 10, 'order_multiple' => 6])
            ->assertStatus(200)->assertJsonPath('order_multiple', 6);
        $this->reMake();
        $this->assertSame(12, $prod->fresh()->cantidadSugerida(7), 'mínimo 10 y múltiplo 6 → 12');
        $this->assertSame(18, $prod->fresh()->cantidadSugerida(13), '13 sube al siguiente múltiplo');

        $this->withHeaders($this->h())->putJson("/api/compras/replenishment/products/{$prod->id}/purchase-terms", ['provider_id' => $prov->id, 'min_order_quantity' => 0])->assertStatus(422);
        $this->reMake();
        $otro = $this->provider();
        $this->withHeaders($this->h())->putJson("/api/compras/replenishment/products/{$prod->id}/purchase-terms", ['provider_id' => $otro->id])->assertStatus(422);

        $this->withHeaders($this->h())->putJson("/api/compras/replenishment/products/{$prod->id}/purchase-terms", ['provider_id' => $prov->id])->assertStatus(200);
        $this->reMake();
        $this->assertSame(7, $prod->fresh()->cantidadSugerida(7), 'vacío = sin restricción');
    }

    public function test_condiciones_de_compra_exigen_compras_edit(): void
    {
        $this->grant(['compras.read', 'compras.write']);
        $prov = $this->provider();
        $prod = $this->product(['provider_id' => $prov->id]);
        $this->withHeaders($this->h())->putJson("/api/compras/replenishment/products/{$prod->id}/purchase-terms", ['provider_id' => $prov->id, 'min_order_quantity' => 5])->assertStatus(403);
    }

    // ── Grupo 6 ─────────────────────────────────────────────────────────────────

    public function test_familia_se_edita_renombra_desactiva_y_valida_nombre_repetido(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        $a = CatalogProductFamily::create(['name' => 'Familia A '.uniqid(), 'is_active' => true]);
        $b = CatalogProductFamily::create(['name' => 'Familia B '.uniqid(), 'is_active' => true]);

        $this->withHeaders($this->h())->patchJson("/api/compras/families/{$b->id}", ['name' => strtolower($a->name)])->assertStatus(422);
        $this->withHeaders($this->h())->patchJson("/api/compras/families/{$b->id}", ['name' => 'Renombrada', 'currency' => 'eur', 'modality' => 'zona_libre'])
            ->assertStatus(200)->assertJsonPath('name', 'Renombrada')->assertJsonPath('currency', 'EUR')->assertJsonPath('modality', 'zona_libre');
        $this->withHeaders($this->h())->patchJson("/api/compras/families/{$b->id}", ['modality' => 'aereo'])->assertStatus(422);

        $this->withHeaders($this->h())->patchJson("/api/compras/families/{$b->id}", ['is_active' => false])->assertStatus(200);
        $ids = collect($this->withHeaders($this->h())->getJson('/api/compras/families')->json('data'))->pluck('id')->all();
        $this->assertNotContains($b->id, $ids);
        $ids = collect($this->withHeaders($this->h())->getJson('/api/compras/families?incluir_inactivas=1')->json('data'))->pluck('id')->all();
        $this->assertContains($b->id, $ids);
        $this->assertDatabaseHas('atlantic_ventas_diseno.catalog_product_families', ['id' => $b->id]);
    }

    public function test_familia_exige_compras_edit(): void
    {
        $this->grant(['compras.read']);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $f = CatalogProductFamily::create(['name' => 'Fam '.uniqid(), 'is_active' => true]);
        $this->withHeaders($this->h())->patchJson("/api/compras/families/{$f->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_generar_compra_de_familia_usa_su_moneda_modalidad_y_el_multiplo(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        $prov = $this->provider();
        $prod = $this->product(['provider_id' => $prov->id, 'reorder_point' => 7, 'stock_quantity' => 0]);
        $prod->syncProviders([$prov->id], $prov->id);
        $prod->providers()->updateExistingPivot($prov->id, ['order_multiple' => 5]);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $f = CatalogProductFamily::create(['name' => 'Fam '.uniqid(), 'is_active' => true, 'currency' => 'EUR', 'modality' => 'directo']);
        $f->products()->attach($prod->id);

        $ids = $this->withHeaders($this->h())->postJson("/api/compras/families/{$f->id}/generate-purchase")->assertStatus(201)->json('order_ids');
        $this->reMake();
        $orden = PurchaseOrder::with('lines')->find($ids[0]);
        $this->assertSame('EUR', $orden?->currency);
        $this->assertSame(10, (int) $orden?->lines->first()?->quantity, '7 faltantes con múltiplo 5 → 10');
    }

    public function test_detalle_de_familia_suma_existencias_por_almacen(): void
    {
        $this->grant(['compras.read']);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $f = CatalogProductFamily::create(['name' => 'Fam '.uniqid(), 'is_active' => true]);
        $r = $this->withHeaders($this->h())->getJson("/api/compras/families/{$f->id}")->assertStatus(200);
        $this->assertSame([], $r->json('stock_por_almacen'));
        $r->assertJsonPath('currency', 'USD')->assertJsonPath('modality', 'directo');
    }

    // ── Grupo 7 ─────────────────────────────────────────────────────────────────

    private function ordenConDoc(): array
    {
        $o = PurchaseOrder::create(['provider_id' => $this->provider()->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(), 'total_amount' => 50]);
        $id = (int) $this->withHeaders($this->h())->postJson("/api/compras/orders/{$o->id}/documents", ['category' => PurchaseOrderDocument::CATEGORY_BL, 'file' => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf')])->assertStatus(201)->json('id');

        return [$o, $id];
    }

    public function test_reemplazar_documento_es_una_sola_operacion_con_motivo(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        [$o, $viejo] = $this->ordenConDoc();
        $url = "/api/compras/orders/{$o->id}/documents/{$viejo}/replace";

        $this->withHeaders($this->h())->post($url, ['file' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'), 'motivo' => 'corto'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertCount(1, $this->withHeaders($this->h())->getJson("/api/compras/orders/{$o->id}/documents")->json('data'));

        $nuevo = $this->withHeaders($this->h())->post($url, ['file' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'), 'motivo' => 'Versión corregida del documento'], ['Accept' => 'application/json'])->assertStatus(201)->json('id');
        $lista = $this->withHeaders($this->h())->getJson("/api/compras/orders/{$o->id}/documents")->json('data');
        $this->assertSame([$nuevo], array_column($lista, 'id'), 'solo queda el nuevo en el checklist');
        $this->assertNotNull(DB::table('atlantic_compras.purchase_order_documents')->where('id', $viejo)->value('deleted_at'));
    }

    public function test_reemplazo_exige_compras_edit_y_no_cruza_ordenes(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        [$o, $doc] = $this->ordenConDoc();
        $otra = PurchaseOrder::create(['provider_id' => $this->provider()->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(), 'total_amount' => 1]);
        $payload = ['file' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'), 'motivo' => 'Versión corregida del documento'];
        $this->withHeaders($this->h())->post("/api/compras/orders/{$otra->id}/documents/{$doc}/replace", $payload, ['Accept' => 'application/json'])->assertStatus(404);

        $this->grant(['compras.read']);
        $this->withHeaders($this->h())->post("/api/compras/orders/{$o->id}/documents/{$doc}/replace", $payload, ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_historial_de_envio_incluye_llegada_y_tipo_de_envio(): void
    {
        $this->grant(['compras.read', 'compras.edit']);
        $o = PurchaseOrder::create(['provider_id' => $this->provider()->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(), 'total_amount' => 50]);
        $this->withHeaders($this->h())->patchJson("/api/compras/orders/{$o->id}/shipping-info", ['carrier' => 'Maersk'])->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        PurchaseOrder::find($o->id)?->update(['estimated_arrival_date' => now()->addDays(10)->toDateString(), 'shipping_type' => 'maritimo']);
        $campos = collect($this->withHeaders($this->h())->getJson("/api/compras/orders/{$o->id}/shipping-history")->json('data'))->pluck('field')->all();
        $this->assertContains('carrier', $campos);
        $this->assertContains('estimated_arrival_date', $campos);
        $this->assertContains('shipping_type', $campos);
    }
}
