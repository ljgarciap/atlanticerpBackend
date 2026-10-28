<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-SCRUM819 (extraccion-bancaria-ocr-ia.md) — extracción con IA de datos bancarios desde la
 * imagen de respaldo del proveedor, vía tool_choice forzado (mismo mecanismo que
 * ExtractProviderBankInfoJob/ADR-SCRUM211). QUEUE_CONNECTION=sync en testing hace que el Job
 * corra dentro del propio request HTTP.
 */
class ProviderBankInfoExtractionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        Cache::flush(); // limpia también el estado de RateLimiter (usa el cache store default)
        // 'anthropic' es el circuit breaker compartido por TODOS los jobs de IA — limpiarlo acá
        // evita que un test anterior que lo dejó abierto contamine estos.
        Redis::del('cb:anthropic:opened_at');
        Redis::del('cb:anthropic:failures');
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

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD', ...$overrides]);
    }

    /** @param array<string, mixed> $input */
    private function fakeToolUseResponse(array $input): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_test',
                    'name'  => 'extract_bank_data',
                    'input' => $input,
                ]],
            ], 200),
        ]);
    }

    private function uploadBankImage(string $token, int $providerId, string $filename = 'datos.jpg'): void
    {
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$providerId}/bank-image", [
                'file' => UploadedFile::fake()->image($filename),
            ])
            ->assertStatus(200);
    }

    public function test_extraccion_completa_pre_llena_los_4_campos(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'bank_name'           => 'Banco Demo A',
            'bank_account_number' => '04-01-01-000000-1',
            'bank_swift'          => 'BAGEPAPA',
            'bank_beneficiary'    => 'Proveedor Demo A SA',
            'fields_found'        => ['bank_name', 'bank_account_number', 'bank_swift', 'bank_beneficiary'],
            'confidence'          => 'alta',
        ]);

        $this->uploadBankImage($token, $provider->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.bank_name', 'Banco Demo A');
        $poll->assertJsonPath('result.bank_account_number', '04-01-01-000000-1');
        $poll->assertJsonPath('result.confidence', 'alta');
        $this->assertCount(4, $poll->json('result.fields_found'));

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::AI_PROVIDER_BANK_INFO_EXTRACTED)
            ->where('entity_id', (string) $provider->id)
            ->first();
        $this->assertNotNull($log, 'Se esperaba una entrada en audit_logs por cada corrida del Job.');
    }

    public function test_extraccion_parcial_solo_reporta_los_campos_encontrados(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'bank_name'           => 'Banco Demo A',
            'bank_account_number' => null,
            'bank_swift'          => null,
            'bank_beneficiary'    => null,
            'fields_found'        => ['bank_name'],
            'confidence'          => 'media',
        ]);

        $this->uploadBankImage($token, $provider->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('result.fields_found', ['bank_name']);
        $poll->assertJsonPath('result.bank_account_number', null);
    }

    public function test_subir_imagen_dispara_extraccion_automaticamente(): void
    {
        // Mismo criterio que SCRUM-211 (test_subir_confirmacion_dispara_validacion_automaticamente)
        // — pedido explícito de Luis: automático, sin botón manual.
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse(['fields_found' => ['bank_name'], 'bank_name' => 'X', 'confidence' => 'alta']);

        $this->uploadBankImage($token, $provider->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);
        $poll->assertJsonPath('status', 'completed');
        Http::assertSentCount(1);
    }

    public function test_poll_sin_ninguna_imagen_subida_retorna_null(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);

        $response->assertJsonPath('status', null);
    }

    public function test_resultado_con_campo_fuera_del_enum_se_descarta_por_completo(): void
    {
        // Checklist del ADR: "no confiar ciegamente" — un fields_found con un valor que no está
        // en el schema (alucinación del modelo, o un intento de manipulación) invalida TODO el
        // resultado en vez de guardarse a medias.
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse([
            'fields_found' => ['bank_name', 'campo_inventado'],
            'bank_name'    => 'Banco Demo A',
            'confidence'   => 'alta',
        ]);

        $this->uploadBankImage($token, $provider->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'failed');
        $this->assertNull($poll->json('result'));
    }

    public function test_circuito_abierto_falla_sin_bloquear_el_resto(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Circuito 'anthropic' compartido por todos los jobs de IA — forzarlo abierto directo en
        // Redis (mismo layout que CircuitBreaker: cb:{name}:opened_at, sin TTL).
        Redis::set('cb:anthropic:opened_at', (string) time());

        $this->uploadBankImage($token, $provider->id);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}/bank-image/extraction")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('no está disponible', (string) $poll->json('error'));

        // El upload en sí (el dato bancario visual) no se ve afectado por el fallo de IA. El Job
        // (queue sync) resetea el binding scoped de tenant al terminar — re-establecerlo antes de
        // una query Eloquent directa (mismo patrón que test_subir_una_segunda_imagen_reemplaza_la_primera).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $fresh = Provider::find($provider->id);
        $this->assertNotNull($fresh->bank_image_storage_key);

        Redis::del('cb:anthropic:opened_at');
    }

    public function test_limite_de_extracciones_por_proveedor_deja_de_disparar_sin_bloquear_el_upload(): void
    {
        // Decisión de implementación (no 100% especificada en el ADR): a diferencia de
        // ADR-SCRUM211 (un endpoint /validate manual, separado del upload, que sí puede devolver
        // 429), acá el trigger es automático DENTRO de la respuesta de upload — devolver 429 ahí
        // bloquearía la imagen en sí, que el ADR pide explícitamente que nunca se bloquee. En vez
        // de eso, superado el límite el upload sigue devolviendo 200 pero con
        // extraction_job_id: null (no se disparó extracción esa vez).
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->fakeToolUseResponse(['fields_found' => [], 'confidence' => 'baja']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                    'file' => UploadedFile::fake()->image("datos-{$i}.jpg"),
                ])
                ->assertStatus(200);
            $this->assertNotNull($response->json('extraction_job_id'));
        }

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                'file' => UploadedFile::fake()->image('datos-6.jpg'),
            ])
            ->assertStatus(200);

        $this->assertNull($response->json('extraction_job_id'));
        $this->assertNotNull($response->json('bank_image.url'), 'La imagen debe seguir subiéndose aunque no se dispare extracción.');
    }

    public function test_poll_sin_permiso_read_retorna_403(): void
    {
        $this->grantCompras('vendedor@atlantic.test', []);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers/999/bank-image/extraction')
            ->assertStatus(403);
    }
}
