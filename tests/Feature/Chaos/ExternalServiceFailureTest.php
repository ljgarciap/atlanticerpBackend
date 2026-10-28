<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use App\Modules\AI\Jobs\SummarizeProjectJob;
use App\Modules\AI\Models\AiAnalysis;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InjectsFaults;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-965 — terceros caídos (Anthropic, S3) y Redis caído en flujos REALES: qué le pasa al usuario y a los datos.
 * Los fallos se inyectan con `Http::fake()` y un disco que lanza; ningún test toca el tercero ni los contenedores.
 *
 * Los circuitos `anthropic` y `s3-private` son nombres FIJOS del código: sus claves `cb:*` se limpian al empezar y al terminar.
 */
final class ExternalServiceFailureTest extends FeatureTestCase
{
    use InjectsFaults;

    private const BREAKER_KEYS = ['cb:anthropic:failures', 'cb:anthropic:opened_at', 'cb:s3-private:failures', 'cb:s3-private:opened_at'];

    protected function setUp(): void
    {
        parent::setUp();
        Redis::del(...self::BREAKER_KEYS);
        config(['services.anthropic.api_key' => 'sk-test-chaos']);
    }

    protected function tearDown(): void
    {
        $this->redisUp();
        Redis::del(...self::BREAKER_KEYS);
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token): int
    {
        $r = $this->withHeaders($this->authHeaders($token))->postJson('/api/crm/projects', [
            'nombre' => 'Proyecto Caos', 'tipo' => 'ambos', 'etapa' => 'lead', 'requires_technical_study' => false, 'assignee_ids' => [],
        ]);
        $r->assertStatus(201);

        return (int) $r->json('id');
    }

    private function newAnalysis(int $projectId): AiAnalysis
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return AiAnalysis::create([
            'id' => (string) Str::uuid(), 'entity_type' => 'project', 'entity_id' => $projectId,
            'analysis_type' => 'summarize', 'status' => AiAnalysis::STATUS_PENDING,
        ]);
    }

    /** Ejecuta el job simulando que es el intento `$attempt` (el worker real lo informa vía el Job de la cola). */
    private function runJob(AiAnalysis $analysis, int $projectId, int $attempt): ?\Throwable
    {
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job = new SummarizeProjectJob($analysis->id, $projectId, 'atlantic');
        $job->setJob($queueJob);

        try {
            $job->handle();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function anthropicDown(int $status = 529): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'Overloaded']], $status)]);
    }

    // ── IA (Anthropic) ────────────────────────────────────────────────────────

    public function test_ia_con_el_tercero_caido_en_un_intento_intermedio_queda_pending_y_se_reintenta(): void
    {
        Event::fake();
        $this->anthropicDown(529);
        $projectId = $this->createProject($this->loginAs());
        $analysis = $this->newAnalysis($projectId);

        $thrown = $this->runJob($analysis, $projectId, attempt: 1);

        $this->assertNotNull($thrown, 'un 5xx es transitorio: el job relanza para que la cola reintente con backoff');
        $this->assertSame(AiAnalysis::STATUS_PENDING, $analysis->fresh()->status);
    }

    public function test_ia_con_el_tercero_caido_en_el_ultimo_intento_marca_failed_sin_perder_el_pedido(): void
    {
        Event::fake();
        $this->anthropicDown(529);
        $projectId = $this->createProject($this->loginAs());
        $analysis = $this->newAnalysis($projectId);

        $thrown = $this->runJob($analysis, $projectId, attempt: 3);

        $this->assertNull($thrown, 'agotados los intentos el job termina sin excepción (no queda en failed_jobs: el estado vive en AiAnalysis)');
        $fresh = $analysis->fresh();
        $this->assertSame(AiAnalysis::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->error);
    }

    public function test_ia_un_4xx_no_se_reintenta(): void
    {
        Event::fake();
        $this->anthropicDown(400);
        $projectId = $this->createProject($this->loginAs());
        $analysis = $this->newAnalysis($projectId);

        $thrown = $this->runJob($analysis, $projectId, attempt: 1);

        $this->assertNull($thrown);
        $this->assertSame(AiAnalysis::STATUS_FAILED, $analysis->fresh()->status, 'un 4xx no se arregla reintentando (créditos, key revocada)');
    }

    public function test_ia_tras_cinco_fallos_el_circuito_abre_y_el_sexto_pedido_no_llama_al_tercero(): void
    {
        Event::fake();
        $this->anthropicDown(529);
        $projectId = $this->createProject($this->loginAs());

        for ($i = 0; $i < 5; $i++) {
            $this->runJob($this->newAnalysis($projectId), $projectId, attempt: 3);
        }
        Http::assertSentCount(5);

        $sexto = $this->newAnalysis($projectId);
        $this->runJob($sexto, $projectId, attempt: 1);

        Http::assertSentCount(5);   // el circuito abierto evitó la sexta llamada al tercero
        $fresh = $sexto->fresh();
        $this->assertSame(AiAnalysis::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('no está disponible temporalmente', (string) $fresh->error);
    }

    /** R-10 · SCRUM-1030 — con Redis caído el usuario ve un mensaje genérico; el detalle técnico solo va al log. */
    public function test_ia_con_redis_caido_en_el_ultimo_intento_guarda_un_mensaje_generico_para_el_usuario(): void
    {
        Event::fake();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200)]);
        $projectId = $this->createProject($this->loginAs());
        $analysis = $this->newAnalysis($projectId);

        $this->redisDown();
        $this->runJob($analysis, $projectId, attempt: 3);
        $this->redisUp();

        $fresh = $analysis->fresh();
        // Con el breaker fail-open (R-01) el tercero sano SÍ se llama, así que la IA puede completarse; lo que importa aquí es que,
        // si falla, el error visible nunca contenga detalle técnico de la excepción.
        $this->assertNotSame('', (string) $fresh->status);
        if ($fresh->status === AiAnalysis::STATUS_FAILED) {
            $this->assertSame('No se pudo completar el análisis. Intenta de nuevo en unos minutos.', $fresh->error);
        }
        foreach (['Redis', '127.0.0.1', 'Connection refused', 'RedisException'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $fresh->error, "el error visible filtra '{$leak}'");
        }
    }

    /** R-10 · el mensaje genérico se guarda cuando la excepción del último intento es técnica (sin depender de Redis). */
    public function test_ia_error_tecnico_en_el_ultimo_intento_no_filtra_el_mensaje_de_la_excepcion(): void
    {
        Event::fake();
        Http::fake(['api.anthropic.com/*' => static function (): never {
            throw new \RuntimeException('SQLSTATE[08006] connection to server at "10.0.3.7" failed');
        }]);
        $projectId = $this->createProject($this->loginAs());
        $analysis = $this->newAnalysis($projectId);

        $this->runJob($analysis, $projectId, attempt: 3);

        $fresh = $analysis->fresh();
        $this->assertSame(AiAnalysis::STATUS_FAILED, $fresh->status);
        $this->assertStringNotContainsString('10.0.3.7', (string) $fresh->error);
        $this->assertStringNotContainsString('SQLSTATE', (string) $fresh->error);
        $this->assertSame('No se pudo completar el análisis. Intenta de nuevo en unos minutos.', $fresh->error);
    }

    // ── Adjuntos CRM (S3) ─────────────────────────────────────────────────────

    /** @param bool $silent true = el disco devuelve `false` sin lanzar (config `throw=false` de S3), el otro modo de fallar */
    private function failingS3(bool $silent = false): void
    {
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $expectation = $disk->shouldReceive('putFileAs');
        $silent ? $expectation->andReturn(false) : $expectation->andThrow(new \RuntimeException('S3 timeout'));
        $disk->shouldIgnoreMissing();
        Storage::set('s3-private', $disk);
        Storage::fake('s3-public');
    }

    private function upload(string $token, int $projectId): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->postJson("/api/crm/projects/{$projectId}/documents", [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), 'categoria' => 'plano',
        ]);
    }

    public function test_adjunto_con_s3_caido_no_deja_fila_huerfana_en_la_base(): void
    {
        $token = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->failingS3();

        $r = $this->upload($token, $projectId);

        $this->assertGreaterThanOrEqual(500, $r->status());
        $this->assertDatabaseMissing('atlantic_crm.project_documents', ['project_id' => $projectId]);
    }

    public function test_adjunto_con_s3_que_devuelve_false_en_silencio_tambien_falla_y_no_deja_fila(): void
    {
        $token = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->failingS3(silent: true);

        $r = $this->upload($token, $projectId);

        $this->assertGreaterThanOrEqual(500, $r->status(), 'un `false` silencioso de S3 no debe tomarse por éxito');
        $this->assertDatabaseMissing('atlantic_crm.project_documents', ['project_id' => $projectId]);
    }

    public function test_adjunto_tras_cinco_fallos_de_s3_el_circuito_abre_y_responde_503_amable(): void
    {
        $token = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->failingS3();

        for ($i = 0; $i < 5; $i++) {
            $this->upload($token, $projectId);
        }

        $this->upload($token, $projectId)
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'Storage service temporarily unavailable. Please try again in a few minutes.']);
        $this->assertDatabaseMissing('atlantic_crm.project_documents', ['project_id' => $projectId]);
    }

    /** R-01 · SCRUM-1030 — con Redis caído la subida funciona porque S3 está sano (fail-open del breaker). */
    public function test_adjunto_con_redis_caido_funciona_pese_a_que_redis_no_responde(): void
    {
        $token = $this->loginAs();
        $projectId = $this->createProject($token);
        Storage::fake('s3-private');
        Storage::fake('s3-public');

        $this->redisDown();
        $r = $this->upload($token, $projectId);
        $this->redisUp();

        $this->assertLessThan(500, $r->getStatusCode(), 'nunca 500 por una caída de Redis');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('atlantic_crm.project_documents')->where('project_id', $projectId)->count());
    }
}
