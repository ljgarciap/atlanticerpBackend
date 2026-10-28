<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiAnalysis;
use App\Modules\AI\Models\AiModelPricing;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/**
 * Auditoría de consumo de IA (pedido de Luis 2026-07-20, tras SCRUM-224): captura de tokens/costo
 * en BaseAiJob + endpoint superadmin GET /api/admin/ai/usage.
 */
class AiUsageTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
    }

    private function createProject(string $token): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre' => 'Proyecto Usage Test', 'tipo' => 'diseno', 'etapa' => 'lead',
                'dialux' => false, 'contacto' => 'Cliente Test', 'valor' => 1000, 'assignee_ids' => [],
            ]);
        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    public function test_job_completado_persiste_modelo_tokens_y_costo(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Resumen de prueba.']],
                'usage'   => ['input_tokens' => 500, 'output_tokens' => 200],
            ], 200),
        ]);

        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);
        $create->assertStatus(202);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $analysis = AiAnalysis::query()->findOrFail($create->json('job_id'));
        Tenant::forgetCurrent();

        $this->assertSame(AiAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertSame('claude-haiku-4-5-20251001', $analysis->model);
        $this->assertSame(500, $analysis->input_tokens);
        $this->assertSame(200, $analysis->output_tokens);

        // Seed: haiku a 1.00/5.00 por millón — 500 in + 200 out.
        $expectedCost = (500 / 1_000_000 * 1.00) + (200 / 1_000_000 * 5.00);
        $this->assertEqualsWithDelta($expectedCost, $analysis->estimated_cost_usd, 0.0000001);
    }

    public function test_job_completado_sin_tarifa_configurada_deja_costo_null(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AiModelPricing::query()->where('model', 'claude-haiku-4-5-20251001')->delete();
        Tenant::forgetCurrent();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Resumen de prueba.']],
                'usage'   => ['input_tokens' => 500, 'output_tokens' => 200],
            ], 200),
        ]);

        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ai/summarize', ['project_id' => $projectId]);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $analysis = AiAnalysis::query()->findOrFail($create->json('job_id'));
        Tenant::forgetCurrent();

        $this->assertSame(500, $analysis->input_tokens);
        $this->assertNull($analysis->estimated_cost_usd);
    }

    public function test_index_requiere_permiso_superadmin(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/ai/usage')
            ->assertStatus(403);
    }

    public function test_index_retorna_datos_paginados_con_totales_y_documento(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $requester = \App\Models\User::where('email', 'vendedor@atlantic.test')->firstOrFail();

        AiAnalysis::create([
            'entity_type' => 'project', 'entity_id' => null,
            'analysis_type' => 'goods_receipt_invoice_match',
            'model' => 'claude-sonnet-5', 'input_tokens' => 1000, 'output_tokens' => 300,
            'estimated_cost_usd' => 0.0075,
            'status' => AiAnalysis::STATUS_COMPLETED, 'result' => '{}',
            'requested_by' => $requester->id,
            'extra' => ['original_filename' => 'factura-123.pdf'],
        ]);
        AiAnalysis::create([
            'entity_type' => 'project', 'entity_id' => null, 'analysis_type' => 'summarize',
            'model' => 'claude-haiku-4-5-20251001', 'input_tokens' => 500, 'output_tokens' => 200,
            'estimated_cost_usd' => 0.0015,
            'status' => AiAnalysis::STATUS_COMPLETED, 'result' => '{}',
            'requested_by' => $requester->id,
            'extra' => [],
        ]);
        Tenant::forgetCurrent();

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/ai/usage')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'created_at', 'analysis_type', 'analysis_label', 'model',
                    'input_tokens', 'output_tokens', 'estimated_cost_usd', 'document_name',
                    'status', 'requested_by' => ['id', 'name']]],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
                'totals' => ['current_month', 'all_time'],
            ]);

        $invoice = collect($response->json('data'))->firstWhere('analysis_type', 'goods_receipt_invoice_match');
        $this->assertSame('factura-123.pdf', $invoice['document_name']);
        $this->assertSame('Detección de factura', $invoice['analysis_label']);
        $this->assertSame($requester->id, $invoice['requested_by']['id']);

        $this->assertGreaterThanOrEqual(1500, $response->json('totals.all_time.input_tokens'));
    }
}
