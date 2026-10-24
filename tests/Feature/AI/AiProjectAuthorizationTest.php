<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiAnalysis;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1056 (I-01, I-02, I-03 de THREAT-MODEL-ia.md): las funciones de IA de proyecto aplican la MISMA regla de acceso que el CRM
 * (superadmin, management o asignado) con las mismas respuestas (404 si no existe, 403 si no hay acceso), y los análisis solo los lee
 * quien los pidió o quien puede ver la entidad.
 */
class AiProjectAuthorizationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Texto generado.']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        ], 200)]);
    }

    private function analisisCreados(): int
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        return AiAnalysis::query()->count();
    }

    /** @param list<int> $assignees */
    private function proyecto(string $token, array $assignees = []): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))->postJson('/api/crm/projects', [
            'nombre' => 'PROYECTO-CONFIDENCIAL-XYZ', 'tipo' => 'ambos', 'etapa' => 'lead', 'dialux' => false,
            'assignee_ids' => $assignees, 'contacto' => 'Cliente VIP', 'notas' => 'margen 38 por ciento',
        ])->assertStatus(201)->json('id');
    }

    private function designer(): string
    {
        return $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function funciones(): array
    {
        return [
            'resumen'            => ['/api/ai/summarize', []],
            'acciones sugeridas' => ['/api/ai/suggest-actions', []],
            'borrador'           => ['/api/ai/draft-message', ['message_type' => 'email', 'intent' => 'seguimiento']],
        ];
    }

    private function enviadoATercero(): string
    {
        return collect(Http::recorded())->map(fn ($par) => $par[0]->body())->implode("\n");
    }

    /**
     * @param array<string, mixed> $extra
     * @dataProvider funciones
     */
    public function test_sin_acceso_al_proyecto_da_403_sin_crear_analisis_ni_llamar_al_tercero(string $url, array $extra): void
    {
        $pid = $this->proyecto($this->loginAs());   // sin asignados: el designer no lo ve en el CRM

        $this->withHeaders($this->authHeaders($this->designer()))->postJson($url, ['project_id' => $pid, ...$extra])->assertStatus(403);

        $this->assertSame(0, $this->analisisCreados(), 'no se crea ningún análisis');
        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $extra
     * @dataProvider funciones
     */
    public function test_proyecto_inexistente_da_404_y_no_encola_nada(string $url, array $extra): void
    {
        $this->withHeaders($this->authHeaders($this->designer()))->postJson($url, ['project_id' => 99999999, ...$extra])->assertStatus(404);

        $this->assertSame(0, $this->analisisCreados());
        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $extra
     * @dataProvider funciones
     */
    public function test_el_asignado_management_y_superadmin_siguen_pudiendo(string $url, array $extra): void
    {
        $designerId = (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');
        $pid = $this->proyecto($this->loginAs(), [$designerId]);

        $this->withHeaders($this->authHeaders($this->designer()))->postJson($url, ['project_id' => $pid, ...$extra])->assertStatus(202);
        $this->withHeaders($this->authHeaders($this->loginAs()))->postJson($url, ['project_id' => $pid, ...$extra])->assertStatus(202);
    }

    public function test_el_prompt_enviado_al_tercero_nunca_incluye_un_proyecto_ajeno(): void
    {
        $pid = $this->proyecto($this->loginAs());

        $this->withHeaders($this->authHeaders($this->designer()))->postJson('/api/ai/summarize', ['project_id' => $pid])->assertStatus(403);

        $this->assertStringNotContainsString('PROYECTO-CONFIDENCIAL-XYZ', $this->enviadoATercero());
    }

    // ── GET /api/ai/analyses/{id} ─────────────────────────────────────────────

    private function analisisDe(string $tokenSolicitante, int $pid): string
    {
        return (string) $this->withHeaders($this->authHeaders($tokenSolicitante))->postJson('/api/ai/summarize', ['project_id' => $pid])
            ->assertStatus(202)->json('job_id');
    }

    public function test_leer_un_analisis_exige_ser_el_solicitante_o_poder_ver_el_proyecto(): void
    {
        $designerId = (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');
        $mgmt = $this->loginAs();
        $pid = $this->proyecto($mgmt, [$designerId]);
        $id = $this->analisisDe($mgmt, $pid);

        // el asignado (no solicitante) puede verlo: tiene acceso al proyecto
        $this->withHeaders($this->authHeaders($this->designer()))->getJson("/api/ai/analyses/{$id}")->assertOk()->assertJsonPath('id', $id);

        // un usuario sin acceso al proyecto no: 404, igual que si no existiera
        DB::table('atlantic_crm.project_assignees')->where('project_id', $pid)->delete();
        $this->withHeaders($this->authHeaders($this->designer()))->getJson("/api/ai/analyses/{$id}")->assertStatus(404);
        $this->withHeaders($this->authHeaders($mgmt))->getJson("/api/ai/analyses/{$id}")->assertOk();
    }

    public function test_un_id_que_no_es_uuid_da_404_no_500(): void
    {
        $this->withHeaders($this->authHeaders($this->designer()))->getJson('/api/ai/analyses/1')->assertStatus(404);
    }

    public function test_un_analisis_de_otro_tipo_solo_lo_lee_su_solicitante_o_superadmin(): void
    {
        $mgmt = $this->loginAs();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        $analysis = AiAnalysis::create([
            'entity_type' => 'provider', 'entity_id' => 1, 'analysis_type' => 'provider_bank_info', 'status' => AiAnalysis::STATUS_COMPLETED,
            'requested_by' => (int) DB::table('atlantic_auth.users')->where('email', 'management@atlantic.test')->value('id'),
            'result' => json_encode(['bank_account_number' => '000111222']),
        ]);

        $this->withHeaders($this->authHeaders($this->designer()))->getJson("/api/ai/analyses/{$analysis->id}")->assertStatus(404);
        $this->withHeaders($this->authHeaders($mgmt))->getJson("/api/ai/analyses/{$analysis->id}")->assertOk();
    }
}
