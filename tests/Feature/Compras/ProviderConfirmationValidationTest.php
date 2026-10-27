<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-211 (ADR-SCRUM211-confirmacion-proveedor-ocr-ia.md) — validación con IA del documento de
 * confirmación de proveedor contra la orden, vía tool_choice forzado. QUEUE_CONNECTION=sync en
 * testing hace que ValidateProviderConfirmationJob corra dentro del propio request HTTP.
 */
class ProviderConfirmationValidationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        Cache::flush(); // limpia también el estado de RateLimiter (usa el cache store default)
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

    private function makeOrderWithLine(): PurchaseOrder
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_ADUANA, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => null,
            'quantity' => 10, 'unit_cost' => 5, 'subtotal' => 50,
        ]);

        return $order;
    }

    /**
     * NOTA (SCRUM-211, fix QA 2026-07-20): subir un documento de categoría confirmación ahora
     * despacha la validación automáticamente — con QUEUE_CONNECTION=sync el Job corre dentro de
     * este mismo request, así que todo Http::fake() debe registrarse ANTES de llamar acá.
     */
    private function uploadConfirmationDocument(string $token, int $orderId, string $filename = 'confirmacion.pdf', string $mime = 'application/pdf', string $category = PurchaseOrderDocument::CATEGORY_CONFIRMACION_PROVEEDOR): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$orderId}/documents", [
                'category' => $category,
                'file' => UploadedFile::fake()->create($filename, 50, $mime),
            ])
            ->assertStatus(201);

        return (int) $response->json('id');
    }

    /** @param array<string, mixed> $input */
    private function fakeToolUseResponse(array $input): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_test',
                    'name'  => 'report_discrepancies',
                    'input' => $input,
                ]],
            ], 200),
        ]);
    }

    public function test_validar_documento_completa_sincronicamente_y_reporta_discrepancias(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'matches_order' => false,
            'discrepancies' => [
                ['campo' => 'cantidad', 'esperado' => '10', 'encontrado' => '8'],
            ],
            'confidence' => 'alta',
        ]);

        $documentId = $this->uploadConfirmationDocument($token, $order->id);

        $trigger = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
            ->assertStatus(202);

        $jobId = (string) $trigger->json('job_id');
        $this->assertNotEmpty($jobId);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.matches_order', false);
        $poll->assertJsonPath('result.confidence', 'alta');
        $this->assertCount(1, $poll->json('result.discrepancies'));

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::AI_PROVIDER_CONFIRMATION_VALIDATED)
            ->where('entity_id', (string) $documentId)
            ->first();
        $this->assertNotNull($log, 'Se esperaba una entrada en audit_logs por cada corrida del Job.');
    }

    public function test_documento_imagen_se_envia_como_content_block_image_no_document(): void
    {
        // Regresión Senior Review 20260718 — completeWithDocumentTool() mandaba siempre
        // type:"document" al armar el content block, aunque SUPPORTED_MEDIA_TYPES incluye
        // image/png|jpeg|webp|gif — la API de Anthropic rechaza un media_type de imagen dentro
        // de un content block "document".
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse(['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta']);

        $documentId = $this->uploadConfirmationDocument($token, $order->id, 'confirmacion.jpg', 'image/jpeg');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
            ->assertStatus(202);

        Http::assertSent(function ($request) {
            $block = $request->data()['messages'][0]['content'][0];

            return $block['type'] === 'image' && $block['source']['media_type'] === 'image/jpeg';
        });

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);
        $poll->assertJsonPath('status', 'completed');
    }

    public function test_poll_de_documento_de_otra_orden_retorna_404(): void
    {
        // Regresión Senior Review 20260718 — validation() buscaba el AiAnalysis solo por
        // documentId, ignorando el orderId de la URL; un documentId real bajo OTRA orden
        // devolvía igual el resultado.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $otherOrder = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->fakeToolUseResponse(['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta']);
        $documentId = $this->uploadConfirmationDocument($token, $order->id);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$otherOrder->id}/documents/{$documentId}/validation")
            ->assertStatus(404);
    }

    public function test_subir_confirmacion_dispara_validacion_automaticamente(): void
    {
        // Hallazgo QA 2026-07-20 (SCRUM-211): el documento se subía y "no pasaba nada" — la
        // validación solo corría detrás de un botón manual, pero el ticket la pide automática.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse(['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta']);

        $documentId = $this->uploadConfirmationDocument($token, $order->id);

        // Sin ningún POST .../validate manual: el upload solo ya debe haber corrido el análisis.
        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.matches_order', true);
        Http::assertSentCount(1);
    }

    public function test_poll_sin_ninguna_validacion_disparada_retorna_null(): void
    {
        // Un documento de categoría distinta a confirmación nunca despacha validación — el poll
        // debe devolver status null, y el upload no debe tocar la API de Anthropic.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        Http::fake(); // cualquier llamada a Anthropic acá sería un bug — no debe dispararse.
        $documentId = $this->uploadConfirmationDocument($token, $order->id, 'factura.pdf', 'application/pdf', PurchaseOrderDocument::CATEGORY_FACTURA_COMERCIAL);
        Http::assertNothingSent();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);

        $response->assertJsonPath('status', null);
    }

    public function test_validar_documento_sin_permiso_edit_retorna_403(): void
    {
        // `permissions` va embebido en el JWT en login (effectivePermissionsV2()) — un cambio en
        // extra_permissions después de emitido el token no lo afecta, hay que re-loguear.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $uploaderToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->fakeToolUseResponse(['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta']);
        $documentId = $this->uploadConfirmationDocument($uploaderToken, $order->id);

        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $readOnlyToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($readOnlyToken))
            ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
            ->assertStatus(403);
    }

    public function test_poll_sin_permiso_read_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/999/validation")
            ->assertStatus(403);
    }

    public function test_tipo_de_documento_no_soportado_falla_el_analisis_sin_llamar_a_claude(): void
    {
        // ZIP pasa el whitelist de extensiones del upload (mismo catálogo para toda categoría),
        // pero el Job de validación solo soporta PDF/imagen — debe fallar limpio, no romper.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        Http::fake(); // cualquier llamada a Anthropic acá sería un bug — no debe dispararse.

        $documentId = $this->uploadConfirmationDocument($token, $order->id, 'confirmacion.zip', 'application/zip');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
            ->assertStatus(202);

        Http::assertNothingSent();

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('no soportado', (string) $poll->json('error'));
    }

    public function test_error_4xx_de_anthropic_falla_al_primer_intento_sin_reintentos(): void
    {
        // Hallazgo QA 2026-07-20 (SCRUM-211/224): con la API devolviendo 400 (ej. créditos
        // agotados), el Job reintentaba con backoff [5s, 30s, 120s] y el usuario quedaba mirando
        // "Validando…" minutos. Un 4xx ≠ 429 no es transitorio: debe fallar al primer intento.
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['message' => 'Your credit balance is too low to access the Anthropic API.'],
            ], 400),
        ]);

        $documentId = $this->uploadConfirmationDocument($token, $order->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validation")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('credit balance', (string) $poll->json('error'));
        Http::assertSentCount(1);
    }

    public function test_limite_de_reintentos_por_orden_retorna_429(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $order = $this->makeOrderWithLine();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse(['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta']);

        // El upload ya consume 1 intento del límite de 5 (validación automática) — quedan 4.
        $documentId = $this->uploadConfirmationDocument($token, $order->id);

        for ($i = 0; $i < 4; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
                ->assertStatus(202);
        }

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents/{$documentId}/validate")
            ->assertStatus(429);
    }
}
