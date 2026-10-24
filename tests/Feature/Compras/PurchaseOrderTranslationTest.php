<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Mail\PurchaseOrderMail;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderTranslation;
use App\Modules\Compras\Models\TranslationCache;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1127 — versión en inglés del PDF de la orden de compra: se genera a pedido, orden por orden; queda guardada; ver/descargar/enviar
 * después no llama a la IA; todo o nada si la IA falla; un idioma por envío de correo.
 */
class PurchaseOrderTranslationTest extends FeatureTestCase
{
    private const EMAIL = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Cache::flush(); // limpia candados, circuit breaker y limitadores (usan el store por defecto)
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
    }

    /** El login/la petición HTTP del test deja el tenant sin activar: se reactiva para que los modelos sigan resolviendo sus schemas. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $response;
    }

    private function slug(): string
    {
        return (string) Tenant::where('slug', 'atlantic')->value('slug');
    }

    /** @param list<string> $perms */
    private function grantCompras(array $perms): void
    {
        DB::table("{$this->slug()}_auth.users")->where('email', self::EMAIL)->update(['extra_permissions' => json_encode($perms)]);
    }

    private function token(): string
    {
        return $this->loginAs(self::EMAIL, 'Password123!', 'designer');
    }

    private function makeOrder(string $productName = 'CINTA NEON 3K 24V 10W 5M/ROLLO IP67', array $providerOverrides = []): PurchaseOrder
    {
        $provider = Provider::create(['name' => 'COXO', 'currency' => 'USD', ...$providerOverrides]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 60,
        ]);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'factory_reference' => 'FAB-1', 'name' => $productName, 'description' => $productName,
            'price_full' => 100, 'cost' => 30, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 2, 'unit_cost' => 30, 'subtotal' => 60,
        ]);

        return $order;
    }

    /** @param array<string, string> $translations */
    private function fakeClaude(array $translations): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => (string) json_encode($translations)]],
            'usage'   => ['input_tokens' => 120, 'output_tokens' => 40],
        ], 200)]);
    }

    private function translate(PurchaseOrder $order): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($this->token()))->postJson("/api/compras/orders/{$order->id}/translation");
    }

    public function test_generar_la_version_en_ingles_la_guarda_la_cachea_y_registra_el_uso_de_ia(): void
    {
        $this->grantCompras(['compras.write', 'compras.read']);
        $order = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);

        $this->translate($order)->assertOk()
            ->assertJsonPath('translations.en.exists', true)
            ->assertJsonPath('translations.en.stale', false);

        $line = $order->lines()->first();
        $saved = PurchaseOrderTranslation::where('purchase_order_id', $order->id)->firstOrFail();
        $this->assertSame('en', $saved->lang);
        $this->assertSame('NEON STRIP 3K 24V 10W 5M/ROLL IP67', $saved->lines[(string) $line->id]);
        $this->assertSame(1, TranslationCache::where('lang', 'en')->count());

        // «Auditoría → Uso de IA»: con modelo, tokens y costo.
        $analysis = AiAnalysis::where('analysis_type', 'order_translation')->firstOrFail();
        $this->assertSame(AiAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertNotNull($analysis->model);
        $this->assertSame(120, $analysis->input_tokens);
        $this->assertSame(40, $analysis->output_tokens);
        $this->assertNotNull($analysis->estimated_cost_usd);

        // La orden registra la generación en la bitácora.
        $this->assertTrue(DB::table("{$this->slug()}_auth.audit_logs")
            ->where('action', 'compras.purchase_order.translation_generated')->where('entity_id', $order->id)->exists());

        // Solo la parte descriptiva viajó a la IA: ni proveedor ni referencias ni montos.
        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'CINTA NEON 3K 24V 10W 5M')
                && ! str_contains($body, 'COXO') && ! str_contains($body, 'FAB-1') && ! str_contains($body, 'REF-');
        });
    }

    public function test_regenerar_con_las_mismas_descripciones_no_vuelve_a_llamar_a_la_ia_y_no_duplica_filas(): void
    {
        $this->grantCompras(['compras.write']);
        $order = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);

        $this->translate($order)->assertOk();
        $this->translate($order)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, PurchaseOrderTranslation::where('purchase_order_id', $order->id)->count());
    }

    public function test_otra_orden_con_la_misma_descripcion_reutiliza_la_cache(): void
    {
        $this->grantCompras(['compras.write']);
        $first = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);
        $this->translate($first)->assertOk();

        $second = $this->makeOrder();
        $this->translate($second)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_si_la_ia_falla_no_se_guarda_nada_y_se_ofrece_el_espanol(): void
    {
        $this->grantCompras(['compras.write', 'compras.read']);
        $order = $this->makeOrder();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->translate($order)->assertStatus(503)
            ->assertJsonPath('code', 'translation_failed')
            ->assertJsonPath('fallback_lang', 'es');

        $this->assertSame(0, PurchaseOrderTranslation::where('purchase_order_id', $order->id)->count());
        $this->assertSame(0, TranslationCache::count());
        $this->assertSame(AiAnalysis::STATUS_FAILED, AiAnalysis::where('analysis_type', 'order_translation')->firstOrFail()->status);

        // El español sigue funcionando exactamente igual.
        $this->withHeaders($this->authHeaders($this->token()))->get("/api/compras/orders/{$order->id}/pdf")->assertOk();
    }

    public function test_si_la_ia_altera_un_dato_tecnico_la_generacion_completa_se_rechaza(): void
    {
        $this->grantCompras(['compras.write']);
        $order = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 12V 10W 5M/ROLL IP67']); // 24V -> 12V

        $this->translate($order)->assertStatus(503);

        $this->assertSame(0, PurchaseOrderTranslation::count());
        $this->assertSame(0, TranslationCache::count());
    }

    public function test_pdf_en_ingles_sin_version_guardada_responde_409_y_nunca_llama_a_la_ia(): void
    {
        $this->grantCompras(['compras.read']);
        $order = $this->makeOrder();
        Http::fake();

        $this->withHeaders($this->authHeaders($this->token()))->getJson("/api/compras/orders/{$order->id}/pdf?lang=en")
            ->assertStatus(409)->assertJsonPath('code', 'translation_missing');

        Http::assertNothingSent();
    }

    public function test_pdf_en_ingles_se_descarga_desde_la_version_guardada_sin_llamar_a_la_ia(): void
    {
        $this->grantCompras(['compras.read', 'compras.write']);
        $order = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);
        $this->translate($order)->assertOk();

        $headers = $this->authHeaders($this->token());
        foreach (['1', '0'] as $includeCost) {
            $response = $this->withHeaders($headers)->get("/api/compras/orders/{$order->id}/pdf?lang=en&include_cost={$includeCost}")->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        Http::assertSentCount(1); // solo la generación
        $this->assertTrue(DB::table("{$this->slug()}_auth.audit_logs")
            ->where('action', 'compras.purchase_order.pdf_downloaded')->where('entity_id', $order->id)
            ->where('metadata', 'like', '%"lang": "en"%')->exists() || DB::table("{$this->slug()}_auth.audit_logs")
            ->where('action', 'compras.purchase_order.pdf_downloaded')->where('entity_id', $order->id)
            ->where('metadata', 'like', '%"lang":"en"%')->exists());
    }

    public function test_vista_en_ingles_traduce_los_textos_fijos_y_las_descripciones_pero_no_los_datos(): void
    {
        $order = $this->makeOrder('CINTA NEON 3K 24V 10W 5M/ROLLO IP67');
        $order->load(['provider', 'lines.catalogProduct']);
        $lineId = $order->lines->first()->id;

        $html = view('compras.purchase-order-pdf', [
            'order' => $order, 'includeCost' => true, 'lang' => 'en',
            'descriptions' => [$lineId => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67'],
            'statusLabel' => 'Ordered', 'shippingTypeLabel' => 'Not specified', 'pageLabel' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
        ])->render();

        foreach (['Purchase Order #', 'Supplier', 'Factory reference', 'Description', 'Unit cost', 'Total', 'NEON STRIP 3K 24V 10W 5M/ROLL IP67'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['Proveedor', 'Orden de Compra', 'Descripción', 'Costo unitario'] as $notExpected) {
            $this->assertStringNotContainsString($notExpected, $html);
        }
        // Referencias, proveedor y montos intactos.
        foreach (['COXO', 'FAB-1', 'REF-', '60.00'] as $intact) {
            $this->assertStringContainsString($intact, $html);
        }
    }

    public function test_vista_sin_idioma_sigue_en_espanol_como_siempre(): void
    {
        $order = $this->makeOrder();
        $order->load(['provider', 'lines.catalogProduct']);

        $html = view('compras.purchase-order-pdf', [
            'order' => $order, 'includeCost' => true, 'statusLabel' => 'Ordenado', 'shippingTypeLabel' => 'Sin especificar',
        ])->render();

        foreach (['Orden de Compra #', 'Proveedor', 'Referencia de fábrica', 'Descripción', 'Costo unitario', 'CINTA NEON 3K 24V 10W 5M/ROLLO IP67'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_si_la_orden_cambia_despues_de_traducir_la_version_queda_desactualizada(): void
    {
        $this->grantCompras(['compras.read', 'compras.write']);
        $order = $this->makeOrder();
        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);
        $this->translate($order)->assertOk();

        $order->lines()->first()->catalogProduct->update(['name' => 'CINTA NEON 4K 24V 10W 5M/ROLLO IP67']);

        $headers = $this->authHeaders($this->token());
        $this->withHeaders($headers)->getJson("/api/compras/orders/{$order->id}/pdf?lang=en")
            ->assertStatus(409)->assertJsonPath('code', 'translation_stale');
        $this->withHeaders($headers)->getJson("/api/compras/orders/{$order->id}")
            ->assertOk()->assertJsonPath('translations.en.exists', true)->assertJsonPath('translations.en.stale', true);
    }

    public function test_correo_en_ingles_exige_version_vigente_y_adjunta_solo_ese_idioma(): void
    {
        $this->grantCompras(['compras.write', 'compras.read']);
        $order = $this->makeOrder(providerOverrides: ['email' => 'supplier@coxo.test']);
        $headers = $this->authHeaders($this->token());

        // Sin versión en inglés: no se encola nada.
        $this->withHeaders($headers)->postJson("/api/compras/orders/{$order->id}/send-email", ['lang' => 'en'])
            ->assertStatus(409)->assertJsonPath('code', 'translation_missing');
        Mail::assertNothingSent();

        $this->fakeClaude(['1' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67']);
        $this->translate($order)->assertOk();

        $this->withHeaders($headers)->postJson("/api/compras/orders/{$order->id}/send-email", ['lang' => 'en', 'include_cost' => true])
            ->assertStatus(202);

        Mail::assertSent(PurchaseOrderMail::class, fn (PurchaseOrderMail $mail): bool => $mail->lang === 'en' && $mail->hasTo('supplier@coxo.test'));
        Mail::assertSent(PurchaseOrderMail::class, 1);
        Http::assertSentCount(1); // el correo no llamó a la IA
    }

    public function test_si_la_version_en_ingles_deja_de_estar_vigente_antes_del_envio_el_job_no_manda_nada_ni_cae_a_espanol(): void
    {
        $this->grantCompras(['compras.write']);
        $order = $this->makeOrder(providerOverrides: ['email' => 'supplier@coxo.test']);

        // Sin versión en inglés (p. ej. la orden cambió entre el pedido y el envío): el job falla sin reintentos y no manda un correo en español.
        (new \App\Modules\Compras\Jobs\SendPurchaseOrderEmailJob($order->id, true, 'en'))
            ->handle(app(\App\Modules\Compras\Services\PurchaseOrderPdfService::class));

        Mail::assertNothingSent();
    }

    public function test_dos_ordenes_con_la_misma_descripcion_generadas_a_la_vez_no_chocan_en_la_cache(): void
    {
        $this->grantCompras(['compras.write']);
        $order = $this->makeOrder();
        // Otra generación ya guardó esa misma descripción en la caché mientras esta esperaba a la IA.
        $hash = \App\Modules\Compras\Support\TranslationGuard::hashText('CINTA NEON 3K 24V 10W 5M/ROLLO IP67');
        Http::fake(function () use ($hash) {
            TranslationCache::query()->insert([
                'source_hash' => $hash, 'lang' => 'en', 'translated' => 'NEON STRIP 3K 24V 10W 5M/ROLL IP67', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return Http::response([
                'content' => [['type' => 'text', 'text' => '{"1": "NEON STRIP 3K 24V 10W 5M/ROLL IP67"}']],
                'usage'   => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200);
        });
        // Fuerza el camino de la IA: la caché estaba vacía al empezar.
        TranslationCache::query()->delete();

        $this->translate($order)->assertOk();

        $this->assertSame(1, TranslationCache::where('source_hash', $hash)->count());
    }

    public function test_correo_sin_idioma_sigue_saliendo_en_espanol(): void
    {
        $this->grantCompras(['compras.write']);
        $order = $this->makeOrder(providerOverrides: ['email' => 'supplier@coxo.test']);

        $this->withHeaders($this->authHeaders($this->token()))->postJson("/api/compras/orders/{$order->id}/send-email")->assertStatus(202);

        Mail::assertSent(PurchaseOrderMail::class, fn (PurchaseOrderMail $mail): bool => $mail->lang === 'es');
    }

    public function test_idioma_no_soportado_se_rechaza(): void
    {
        $this->grantCompras(['compras.write', 'compras.read']);
        $order = $this->makeOrder(providerOverrides: ['email' => 'supplier@coxo.test']);
        $headers = $this->authHeaders($this->token());

        $this->withHeaders($headers)->getJson("/api/compras/orders/{$order->id}/pdf?lang=fr")->assertStatus(422);
        $this->withHeaders($headers)->postJson("/api/compras/orders/{$order->id}/send-email", ['lang' => 'fr'])->assertStatus(422);
    }

    public function test_generar_la_traduccion_exige_permiso_de_escritura_de_compras(): void
    {
        $this->grantCompras(['compras.read']);
        $order = $this->makeOrder();
        Http::fake();

        $this->translate($order)->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_generar_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/compras/orders/1/translation')->assertStatus(401);
    }
}
