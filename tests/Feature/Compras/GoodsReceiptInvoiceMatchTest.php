<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-224 (ADR-SCRUM224-deteccion-factura-ingreso-mercancia.md) — Paso 0 del wizard de Ingreso
 * de Mercancía: detección automática de orden/productos desde una factura adjunta. Mismo patrón
 * de test que ProviderConfirmationValidationTest (SCRUM-211): QUEUE_CONNECTION=sync en testing
 * hace que MatchInvoiceToOrderJob corra dentro del propio request HTTP, y Http::fake() simula la
 * respuesta de Anthropic sin llamar a la API real.
 */
class GoodsReceiptInvoiceMatchTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        Cache::flush();
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
    }

    /** @param list<string> $perms */
    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @return array{order: PurchaseOrder, product: CatalogProduct} */
    private function makeEligibleOrderWithProduct(string $reference = 'LMP-100', string $description = 'Lampara LED 12W', int $quantity = 10, float $unitCost = 5.0): array
    {
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_INTERNACIONAL]);
        $product  = CatalogProduct::create([
            'reference' => $reference, 'name' => $description, 'description' => $description,
            'price_full' => $unitCost * 2, 'cost' => $unitCost, 'stock_quantity' => 0,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_ADUANA, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => $quantity, 'unit_cost' => $unitCost, 'subtotal' => $quantity * $unitCost,
        ]);

        return ['order' => $order, 'product' => $product];
    }

    /** @param array<string, mixed> $input */
    private function fakeToolUseResponse(array $input): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_test',
                    'name'  => 'report_invoice_data',
                    'input' => $input,
                ]],
            ], 200),
        ]);
    }

    public function test_factura_pdf_detecta_orden_y_producto_reales(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        ['order' => $order, 'product' => $product] = $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => (string) $order->id,
            'detected_provider_name'   => 'LightCorp',
            'line_items' => [
                ['reference' => 'LMP-100', 'description' => 'Lampara LED 12W', 'quantity' => 10, 'unit_cost' => 5.0],
            ],
            'confidence' => 'alta',
        ]);

        $upload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(202);

        $jobId = (string) $upload->json('job_id');
        $this->assertNotEmpty($jobId);

        // El content block debe ser "document" (PDF), no texto — completeWithDocumentTool(), no completeWithTool().
        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];

            return is_array($content) && $content[0]['type'] === 'document';
        });

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/goods-receipts/invoice/{$jobId}")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.matched_order_id', $order->id);
        $poll->assertJsonPath('result.unmatched_count', 0);
        $poll->assertJsonPath('result.lines.0.catalog_product_id', $product->id);
        $poll->assertJsonPath('result.lines.0.quantity', 10);
    }

    public function test_factura_excel_se_extrae_a_texto_y_usa_completewithtool_sin_content_block_binario(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        ['order' => $order] = $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => (string) $order->id,
            'detected_provider_name'   => 'LightCorp',
            'line_items' => [
                ['reference' => 'LMP-100', 'description' => 'Lampara LED 12W', 'quantity' => 10, 'unit_cost' => 5.0],
            ],
            'confidence' => 'media',
        ]);

        $csv = "Referencia,Descripcion,Cantidad,Costo\nLMP-100,Lampara LED 12W,10,5.00\n";

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->createWithContent('factura.csv', $csv),
            ])
            ->assertStatus(202);

        // completeWithTool() manda 'content' como STRING plano, no un array de content blocks —
        // si esto fuera un array, significaría que se coló por la rama de documento binario.
        Http::assertSent(function ($request) use ($csv) {
            $content = $request->data()['messages'][0]['content'];

            return is_string($content) && str_contains($content, 'LMP-100');
        });
    }

    public function test_factura_con_un_solo_producto_reconocible_deja_el_otro_sin_detectar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD']);
        $productA = CatalogProduct::create([
            'reference' => 'LMP-100', 'name' => 'Lampara LED 12W', 'description' => 'Lampara LED 12W',
            'price_full' => 10, 'cost' => 5, 'stock_quantity' => 0, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $productB = CatalogProduct::create([
            'reference' => 'LMP-200', 'name' => 'Panel LED 24W', 'description' => 'Panel LED 24W',
            'price_full' => 20, 'cost' => 10, 'stock_quantity' => 0, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_ADUANA, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productA->id, 'quantity' => 5, 'unit_cost' => 5, 'subtotal' => 25]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productB->id, 'quantity' => 3, 'unit_cost' => 10, 'subtotal' => 30]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => (string) $order->id,
            'detected_provider_name'   => 'LightCorp',
            'line_items' => [
                ['reference' => 'LMP-100', 'description' => 'Lampara LED 12W', 'quantity' => 5, 'unit_cost' => 5.0],
                ['reference' => null, 'description' => 'Producto ilegible xyz123', 'quantity' => 1, 'unit_cost' => null],
            ],
            'confidence' => 'baja',
        ]);

        $upload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(202);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/invoice/' . $upload->json('job_id'))
            ->assertStatus(200);

        $poll->assertJsonPath('result.matched_order_id', $order->id);
        $poll->assertJsonPath('result.unmatched_count', 1);
        $this->assertCount(1, $poll->json('result.lines'));
        $poll->assertJsonPath('result.lines.0.catalog_product_id', $productA->id);
    }

    public function test_factura_sin_datos_reconocibles_no_detecta_ninguna_orden(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => null,
            'detected_provider_name'   => null,
            'line_items'               => [],
            'confidence'               => 'baja',
        ]);

        $upload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(202);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/invoice/' . $upload->json('job_id'))
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.matched_order_id', null);
        $poll->assertJsonPath('result.unmatched_count', 0);
    }

    public function test_misma_factura_subida_de_nuevo_tras_orden_recibida_avisa_ya_procesada_sin_llamar_de_nuevo_a_claude(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        ['order' => $order] = $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => (string) $order->id,
            'detected_provider_name'   => 'LightCorp',
            'line_items' => [
                ['reference' => 'LMP-100', 'description' => 'Lampara LED 12W', 'quantity' => 10, 'unit_cost' => 5.0],
            ],
            'confidence' => 'alta',
        ]);

        $sameFile = UploadedFile::fake()->createWithContent('factura.pdf', 'contenido-identico-de-la-factura');

        $firstUpload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', ['file' => $sameFile])
            ->assertStatus(202);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/invoice/' . $firstUpload->json('job_id'))
            ->assertJsonPath('result.matched_order_id', $order->id);

        // El HTTP call anterior resetea el tenant activo (gotcha ya documentado en memoria) —
        // hay que reestablecerlo antes de tocar el modelo directo.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        // La orden llega a "Recibido" — ya no acepta más ingresos.
        $order->update(['status' => PurchaseOrder::STATUS_RECIBIDO]);

        Http::fake(); // cualquier llamada a Anthropic acá sería un bug — no debe dispararse de nuevo.

        $sameFileAgain = UploadedFile::fake()->createWithContent('factura.pdf', 'contenido-identico-de-la-factura');
        $secondUpload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', ['file' => $sameFileAgain])
            ->assertStatus(202);

        Http::assertNothingSent();

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/invoice/' . $secondUpload->json('job_id'))
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.already_processed', true);
        $poll->assertJsonPath('result.matched_order_id', null);
    }

    public function test_limite_de_10_por_hora_por_usuario_retorna_429(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => null, 'detected_provider_name' => null,
            'line_items' => [], 'confidence' => 'baja',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/compras/goods-receipts/invoice', [
                    'file' => UploadedFile::fake()->create("factura{$i}.pdf", 10, 'application/pdf'),
                ])
                ->assertStatus(202);
        }

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura-11.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(429);
    }

    public function test_archivo_mayor_a_10mb_es_rechazado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura-grande.pdf', 10241, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_extension_no_soportada_es_rechazada(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.zip', 50, 'application/zip'),
            ])
            ->assertStatus(422);
    }

    public function test_subir_factura_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    public function test_poll_de_job_inexistente_retorna_status_null(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/invoice/00000000-0000-0000-0000-000000000000')
            ->assertStatus(200);

        $response->assertJsonPath('status', null);
    }

    public function test_cada_corrida_se_audita_incluyendo_matched_order_id_null(): void
    {
        // Cybersecurity gap #4 (ADR-SCRUM224) — se audita CADA corrida, no solo los matches.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => null, 'detected_provider_name' => null,
            'line_items' => [], 'confidence' => 'baja',
        ]);

        $upload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(202);

        $jobId = (string) $upload->json('job_id');

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'ai.goods_receipt_invoice.matched')
            ->where('entity_id', $jobId)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada en audit_logs incluso sin match.');
        $metadata = json_decode((string) $log->metadata, true);
        $this->assertNull($metadata['matched_order_id']);
    }

    public function test_documento_hash_queda_persistido_en_ai_analyses(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $this->makeEligibleOrderWithProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'detected_order_reference' => null, 'detected_provider_name' => null,
            'line_items' => [], 'confidence' => 'baja',
        ]);

        $upload = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/invoice', [
                'file' => UploadedFile::fake()->createWithContent('factura.pdf', 'contenido-de-prueba'),
            ])
            ->assertStatus(202);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $analysis = AiAnalysis::find($upload->json('job_id'));
        $this->assertNotNull($analysis);
        $this->assertSame(hash('sha256', 'contenido-de-prueba'), $analysis->document_hash);
    }
}
