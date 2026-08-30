<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Jobs\DraftMessageJob;
use App\Modules\AI\Jobs\MonthlyAnalysisJob;
use App\Modules\AI\Jobs\SuggestActionsJob;
use App\Modules\AI\Jobs\SummarizeProjectJob;
use App\Modules\AI\Models\AiAnalysis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\FeatureTestCase;

class AIControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // reset rate limiter between test methods
    }
    private function createProject(string $token): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre'       => 'Proyecto AI Test',
                'tipo'         => 'diseno',
                'etapa'        => 'lead',
                'dialux'       => false,
                'contacto'     => 'Cliente AI',
                'valor'        => 100000,
                'assignee_ids' => [],
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    // ── Summarize ─────────────────────────────────────────────────────────────

    public function test_summarize_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/ai/summarize', ['project_id' => 1])->assertStatus(401);
    }

    public function test_summarize_dispatcha_job_y_retorna_job_id(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);

        $response->assertStatus(202)->assertJsonStructure(['job_id']);
        Bus::assertDispatched(SummarizeProjectJob::class);
    }

    public function test_summarize_devuelve_mismo_job_id_si_ya_hay_analisis_pendiente(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $r1 = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);
        $r2 = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);

        $r1->assertStatus(202);
        $r2->assertStatus(202);
        $this->assertEquals($r1->json('job_id'), $r2->json('job_id'));
        Bus::assertDispatchedTimes(SummarizeProjectJob::class, 1);
    }

    // ── Suggest actions ───────────────────────────────────────────────────────

    public function test_suggest_actions_dispatcha_job_y_retorna_job_id(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/suggest-actions', ['project_id' => $projectId]);

        $response->assertStatus(202)->assertJsonStructure(['job_id']);
        Bus::assertDispatched(SuggestActionsJob::class);
    }

    // ── Draft message ─────────────────────────────────────────────────────────

    public function test_draft_message_valida_campos_requeridos(): void
    {
        $token = $this->loginAs();
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/draft-message', ['project_id' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message_type', 'intent']);
    }

    public function test_draft_message_dispatcha_job(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/draft-message', [
                'project_id'   => $projectId,
                'message_type' => 'email',
                'intent'       => 'seguimiento post-reunión',
            ]);

        $response->assertStatus(202)->assertJsonStructure(['job_id']);
        Bus::assertDispatched(DraftMessageJob::class);
    }

    public function test_draft_message_crea_nuevo_job_cada_vez(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $payload = ['project_id' => $projectId, 'message_type' => 'whatsapp', 'intent' => 'cierre'];

        $r1 = $this->withHeaders($this->authHeaders($token))->postJson('/api/ai/draft-message', $payload);
        $r2 = $this->withHeaders($this->authHeaders($token))->postJson('/api/ai/draft-message', $payload);

        $this->assertNotEquals($r1->json('job_id'), $r2->json('job_id'));
        Bus::assertDispatchedTimes(DraftMessageJob::class, 2);
    }

    // ── Monthly analysis ──────────────────────────────────────────────────────

    public function test_monthly_analysis_requiere_rol_management(): void
    {
        Bus::fake();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/monthly-analysis', ['month' => '2026-06'])
            ->assertStatus(403);
    }

    public function test_monthly_analysis_dispatcha_job_para_management(): void
    {
        Bus::fake();
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/monthly-analysis', ['month' => '2026-06']);

        $response->assertStatus(202)->assertJsonStructure(['job_id']);
        Bus::assertDispatched(MonthlyAnalysisJob::class);
    }

    // ── Show (polling) ────────────────────────────────────────────────────────

    public function test_show_retorna_estado_del_analisis(): void
    {
        Bus::fake();
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $createRes = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);
        $createRes->assertStatus(202);

        $jobId = $createRes->json('job_id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ai/analyses/{$jobId}")
            ->assertStatus(200)
            ->assertJsonStructure(['id', 'status', 'result', 'error', 'analysis_type'])
            ->assertJsonFragment(['status' => AiAnalysis::STATUS_PENDING]);
    }

    public function test_show_retorna_404_para_id_inexistente(): void
    {
        $token = $this->loginAs();
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ai/analyses/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}
