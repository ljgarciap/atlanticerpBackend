<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\SafePhotoUrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-245 (REQ-182/183, ADR-SCRUM245) — comparación visual de sustitutos: familia obligatoria,
 * tope duro de 25 candidatos, ranking vía tool_choice forzado. QUEUE_CONNECTION=sync en testing
 * hace que CompareSubstituteCandidatesJob corra dentro del propio request HTTP.
 */
class SubstituteSearchTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        Cache::flush(); // limpia también el estado de RateLimiter (usa el cache store default)
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests', 'catalog.photo_hosts' => ['cdn.test']]);
        // SCRUM-1038: la guarda SSRF resuelve DNS; en pruebas el host permitido resuelve a una IP pública fija.
        $this->app->instance(SafePhotoUrlGuard::class, new SafePhotoUrlGuard(static fn (string $host): array => ['93.184.216.34']));
    }

    /** @param list<string> $perms */
    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);

        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Artículo decorativo E27 Ámbar', 'description' => 'Artículo decorativo E27 Ámbar',
            'price_full' => 20, 'cost' => 12, 'provider_id' => $provider->id, 'is_active' => true,
            'photo_url' => 'https://cdn.test/photo-'.uniqid().'.jpg',
            ...$overrides,
        ]);
    }

    /** @param list<array{catalog_product_id: int, similarity_percent: float, reasoning: string}> $ranking */
    private function fakeAnthropicRanking(array $ranking): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'rank_candidates',
                    'input' => ['ranking' => $ranking],
                ]],
            ], 200),
            'cdn.test/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_busqueda_por_foto_completa_y_devuelve_resultados_con_stock_y_precio_reales(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $candidate = $this->makeProduct(['name' => 'Artículo genérico E27', 'stock_quantity' => 7, 'category' => 'bombillos']);
        $family = CatalogProductFamily::create(['name' => 'Artículos', 'is_active' => true]);
        $family->products()->attach([$candidate->id]);

        $this->fakeAnthropicRanking([
            ['catalog_product_id' => $candidate->id, 'similarity_percent' => 87.5, 'reasoning' => 'Mismo tipo de rosca y forma.'],
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $trigger = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(202);

        $jobId = (string) $trigger->json('job_id');
        $this->assertNotEmpty($jobId);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/replacement-requests/search/{$jobId}")
            ->assertStatus(200);

        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonPath('results.0.catalog_product_id', $candidate->id);
        $poll->assertJsonPath('results.0.similarity_percent', 87.5);
        $poll->assertJsonPath('results.0.stock_quantity', 7);
        $poll->assertJsonPath('results.0.cost', 12);
        // SCRUM-246 (rebote de Gerencia Test) — Categoría, ausente hasta ahora en la tabla.
        $poll->assertJsonPath('results.0.category', 'bombillos');
        $poll->assertJsonPath('results.0.name', 'Artículo genérico E27');

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::AI_SUBSTITUTE_COMPARISON_COMPLETED)
            ->where('entity_id', (string) $original->id)
            ->first();
        $this->assertNotNull($log, 'Se esperaba una entrada en audit_logs por cada corrida del Job.');
        $metadata = json_decode((string) $log->metadata, true);
        $this->assertSame($family->id, $metadata['family_id']);
        $this->assertFalse($metadata['truncated_to_25']);
        $this->assertContains($candidate->id, $metadata['catalog_product_ids']);
    }

    public function test_familia_con_mas_de_25_productos_se_recorta_y_queda_auditado(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Familia grande', 'is_active' => true]);

        $candidateIds = [];
        for ($i = 0; $i < 30; $i++) {
            $candidateIds[] = $this->makeProduct(['description' => "Candidato {$i}"])->id;
        }
        $family->products()->attach($candidateIds);

        // El modelo de IA solo ranquea los primeros 25 que el controller le manda.
        $ranking = array_map(
            fn (int $id) => ['catalog_product_id' => $id, 'similarity_percent' => 50.0, 'reasoning' => 'ok'],
            array_slice($candidateIds, 5, 25), // los 25 más recientes (orderByDesc created_at)
        );
        $this->fakeAnthropicRanking($ranking);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $trigger = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(202);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::AI_SUBSTITUTE_COMPARISON_COMPLETED)
            ->where('entity_id', (string) $original->id)
            ->first();
        $this->assertNotNull($log);
        $metadata = json_decode((string) $log->metadata, true);
        $this->assertTrue($metadata['truncated_to_25']);
        $this->assertSame(30, $metadata['total_family_products']);
        $this->assertCount(25, $metadata['catalog_product_ids']);
    }

    public function test_extension_no_soportada_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Artículos', 'is_active' => true]);
        $family->products()->attach([$this->makeProduct()->id]);

        Http::fake(); // cualquier llamada a Anthropic acá sería un bug.

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.zip', 50, 'application/zip'),
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_familia_sin_productos_activos_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Familia vacía', 'is_active' => true]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(422);
    }

    /** Cybersecurity gap #1 (ADR-SCRUM245) — tope de búsquedas por usuario, no por familia/producto. */
    public function test_limite_de_busquedas_por_usuario_retorna_429(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Artículos', 'is_active' => true]);
        $family->products()->attach([$this->makeProduct()->id]);

        $this->fakeAnthropicRanking([]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/compras/replacement-requests/search', [
                    'original_catalog_product_id' => $original->id,
                    'family_id' => $family->id,
                    'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
                ])
                ->assertStatus(202);
        }

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(429);
    }

    public function test_busqueda_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $original = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Artículos', 'is_active' => true]);
        $family->products()->attach([$this->makeProduct()->id]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(403);
    }

    public function test_imagen_con_texto_inyectado_no_rompe_el_resultado_estructurado(): void
    {
        // Cybersecurity — el ranking sigue siendo el que decide tool_choice forzado, nunca texto
        // libre; esto simula que el modelo (correctamente) ignoró una instrucción embebida y
        // devolvió igual un ranking justificado.
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit', 'compras.read']);
        $original = $this->makeProduct();
        $candidate = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Artículos', 'is_active' => true]);
        $family->products()->attach([$candidate->id]);

        $this->fakeAnthropicRanking([
            ['catalog_product_id' => $candidate->id, 'similarity_percent' => 42.0, 'reasoning' => 'Comparación real, se ignoró texto embebido en la imagen.'],
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $trigger = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests/search', [
                'original_catalog_product_id' => $original->id,
                'family_id' => $family->id,
                'file' => UploadedFile::fake()->create('foto.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(202);

        $poll = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests/search/'.$trigger->json('job_id'))
            ->assertStatus(200);

        $poll->assertJsonPath('results.0.similarity_percent', 42);
    }
}
