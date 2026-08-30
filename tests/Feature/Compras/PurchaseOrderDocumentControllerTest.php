<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-211/REQ-148 RN1 (Senior Review 2026-08-07, hallazgo propio): "el documento únicamente
 * podrá adjuntarse cuando la Orden de Compra ya no se encuentre en estado 'Por aprobar'" nunca se
 * validaba server-side — solo el frontend ocultaba el control de subida. Cubre solo el gate nuevo,
 * no todo el flujo de REQ-148 (que hasta ahora solo tenía verificación vía Pre-QA en vivo, sin
 * tests PHPUnit — fuera de alcance de este fix puntual backfillear esa cobertura completa).
 *
 * CI real (2026-08-07, 2 rondas): sin `Storage::fake('s3-private')` el upload pegaba contra S3
 * real (pasaba en local por las credenciales horneadas del Docker local, no en GitHub Actions).
 * Corregido eso, CI volvió a romper por una segunda causa: subir `confirmacion_proveedor` fuera
 * de "Por aprobar" dispara `ValidateProviderConfirmationJob` de forma síncrona
 * (QUEUE_CONNECTION=sync en testing), que necesita `services.anthropic.api_key` configurada — CI
 * no la tiene. Ambos son exactamente el mismo patrón que ya usa `ProviderConfirmationValidationTest.php`
 * (`Storage::fake` + `config(['services.anthropic.api_key' => ...])` + `Http::fake()`) — debí
 * copiarlo de ahí desde el principio en vez de escribir el setUp() de cero.
 */
class PurchaseOrderDocumentControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Storage::fake('s3-private');
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
        // Ninguno de estos tests le interesa el resultado de la validación con IA, solo que el
        // upload/gate RN1 funcione — pero un `Http::fake()` desnudo (sin forma) hace que
        // AnthropicClient::captureUsage() reciba `null` donde espera un array y tire TypeError,
        // porque el Job corre sincrónico (QUEUE_CONNECTION=sync) dentro del mismo request. Mismo
        // shape de respuesta que `ProviderConfirmationValidationTest::fakeToolUseResponse()`.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_test',
                    'name'  => 'report_discrepancies',
                    'input' => ['matches_order' => true, 'discrepancies' => [], 'confidence' => 'alta'],
                ]],
            ], 200),
        ]);
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    private function makeOrder(int $providerId, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_subir_confirmacion_proveedor_bloqueada_mientras_por_aprobar(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => 'confirmacion_proveedor',
                'file' => UploadedFile::fake()->create('confirmacion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('podrá adjuntarse', $response->json('message'));
    }

    public function test_subir_confirmacion_proveedor_permitida_fuera_de_por_aprobar(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => 'confirmacion_proveedor',
                'file' => UploadedFile::fake()->create('confirmacion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    /** El gate es específico de confirmacion_proveedor — el resto del checklist (REQ-155) no tiene esa regla. */
    public function test_subir_otra_categoria_de_documento_no_se_bloquea_por_aprobar(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => 'factura_comercial',
                'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }
}
