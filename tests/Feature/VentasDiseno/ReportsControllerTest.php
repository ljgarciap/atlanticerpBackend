<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Reportes-A (REQ-071/077/078). Ver memory/project_backlog_ventas_diseno_req.md.
 */
class ReportsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeApprovedCard(
        int $ownerId, float $workedAreaM2, Carbon $stageChangedAt,
        float $amount = 1000, ?string $subClientName = null,
    ): PipelineCard {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => $subClientName ?? 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'worked_area_m2' => $workedAreaM2,
            'amount' => $amount, 'stage_changed_at' => $stageChangedAt,
        ]);
    }

    public function test_reportes_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/reports/summary')->assertStatus(401);
    }

    public function test_superficie_trabajada_suma_solo_tarjetas_aprobadas_del_periodo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $this->makeApprovedCard($designerId, 50.0, now());
        $this->makeApprovedCard($designerId, 30.0, now());
        // Fuera del período (mes pasado) — no debe sumar.
        $this->makeApprovedCard($designerId, 999.0, now()->subMonths(2));

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=own&period=month')
            ->assertStatus(200);

        $response->assertJsonPath('scope', 'own');
        $response->assertJsonPath('surface_m2', 80);
    }

    public function test_diseñador_no_puede_pedir_alcance_equipo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200);

        // Se degrada silenciosamente a "own" — mismo patrón que Pipeline/Home.
        $response->assertJsonPath('scope', 'own');
    }

    public function test_gerencia_puede_pedir_alcance_equipo(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'management@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200);

        $response->assertJsonPath('scope', 'team');
    }

    // ── Mejores clientes (REQ-074) ───────────────────────────────────────────────

    public function test_mejores_clientes_ordena_de_mayor_a_menor(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $this->makeApprovedCard($designerId, 10, now(), 500, 'Cliente Chico');
        $this->makeApprovedCard($designerId, 10, now(), 5000, 'Cliente Grande');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary')
            ->assertStatus(200);

        $bestClients = $response->json('best_clients');
        $this->assertSame('Cliente Grande', $bestClients[0]['client_name']);
        $this->assertEquals(5000, $bestClients[0]['amount']);
        $this->assertSame('Cliente Chico', $bestClients[1]['client_name']);
    }

    // ── Metas (REQ-072/SCRUM-146) ────────────────────────────────────────────────

    public function test_meta_panel_usa_el_valor_configurado_no_un_promedio(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeApprovedCard($designerId, 10, now(), 3000);
        SalesGoal::create(['user_id' => $designerId, 'goal_amount' => 8000]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary')
            ->assertStatus(200);

        $response->assertJsonPath('meta.goal_amount', 8000);
        $history = $response->json('meta.monthly_history');
        $this->assertCount(6, $history);
        $this->assertSame(3000, $history[5]['amount']); // último mes = el actual
    }

    public function test_meta_panel_sin_meta_configurada_es_null(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary')
            ->assertStatus(200);

        $response->assertJsonPath('meta.goal_amount', null);
    }

    // ── Top de vendedores (REQ-075) ──────────────────────────────────────────────

    public function test_top_vendedores_solo_aparece_en_alcance_equipo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=own')
            ->assertStatus(200);

        $response->assertJsonMissingPath('top_vendors');
    }

    public function test_top_vendedores_marca_alerta_tras_3_meses_consecutivos_bajos(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'management@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        // 3 meses consecutivos por debajo de $20,000 (umbral por defecto).
        $this->makeApprovedCard($designerId, 10, now(), 5000);
        $this->makeApprovedCard($designerId, 10, now()->subMonth(), 5000);
        $this->makeApprovedCard($designerId, 10, now()->subMonths(2), 5000);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200);

        $topVendors = $response->json('top_vendors');
        $row = collect($topVendors)->firstWhere('user_id', $designerId);
        $this->assertNotNull($row);
        $this->assertTrue($row['low_sales_alert']);
    }

    public function test_top_vendedores_sin_alerta_si_un_mes_supera_el_umbral(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'management@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $this->makeApprovedCard($designerId, 10, now(), 25000); // mes actual por encima del umbral
        $this->makeApprovedCard($designerId, 10, now()->subMonth(), 5000);
        $this->makeApprovedCard($designerId, 10, now()->subMonths(2), 5000);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200);

        $topVendors = $response->json('top_vendors');
        $row = collect($topVendors)->firstWhere('user_id', $designerId);
        $this->assertNotNull($row);
        $this->assertFalse($row['low_sales_alert']);
    }

    // ── Forecast (REQ-076) ───────────────────────────────────────────────────────

    public function test_forecast_solo_aparece_en_alcance_equipo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=own')
            ->assertStatus(200);

        $response->assertJsonMissingPath('forecast');
    }

    public function test_forecast_pondera_cotizacion_y_propuesta_con_las_probabilidades_configuradas(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'management@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        // Forecast es agregado de TODO el equipo (por diseño, REQ-076) — puede
        // haber datos demo sembrados en el entorno de testing (CrmDemoSeeder),
        // así que se compara por delta (antes/después) en vez de un valor
        // absoluto, para no acoplar el test al estado exacto del seed.
        $before = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->json('forecast');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->makeApprovedCard($designerId, 10, now(), 1000); // ya cerrado

        $master  = MasterClient::create(['name' => 'Cliente Quote']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Quote', 'tax_id' => '155-1111-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Quote']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'amount' => 2000, 'stage_changed_at' => now(),
        ]);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'proposal',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'amount' => 3000, 'stage_changed_at' => now(),
        ]);

        $after = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200)
            ->json('forecast');

        // Ya cerrado: +1000. Mejor caso (sin ajustar): +2000 (quote) +3000 (proposal) = +5000.
        // Ponderado (solo pipeline abierto): 2000*0.40 + 3000*0.70 = 800 + 2100 = +2900.
        // Comprometido (solo Propuesta, sin ajustar): +3000.
        $this->assertEquals(1000, $after['closed'] - $before['closed']);
        $this->assertEquals(5000, $after['best_case'] - $before['best_case']);
        $this->assertEquals(2900, $after['weighted_pipeline'] - $before['weighted_pipeline']);
        $this->assertEquals(3000, $after['committed'] - $before['committed']);
    }

    public function test_forecast_incluye_detalle_por_etapa_con_probabilidad_configurada(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $master  = MasterClient::create(['name' => 'Cliente Breakdown']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Breakdown', 'tax_id' => '155-2222-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Breakdown']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'proposal',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'amount' => 4000, 'stage_changed_at' => now(),
        ]);

        $forecast = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200)
            ->json('forecast');

        $proposalRow = collect($forecast['stage_breakdown'])->firstWhere('stage', 'proposal');
        $this->assertNotNull($proposalRow);
        $this->assertEquals(70, $proposalRow['probability_percent']);
        $this->assertGreaterThanOrEqual(1, $proposalRow['projects']);
    }

    public function test_falta_para_la_meta_ignora_el_pipeline_ponderado(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // Meta bien por encima de cualquier dato demo ambiente, para que "remaining" no
        // toque el piso de 0 en ninguno de los 2 escenarios comparados (delta, no absoluto
        // — mismo criterio que test_forecast_pondera_..., puede haber datos demo sembrados).
        SalesGoal::updateOrCreate(['user_id' => $designerId], ['goal_amount' => 5000000, 'goal_plus_amount' => 6000000]);

        $before = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->json('forecast');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->makeApprovedCard($designerId, 5, now(), 100); // closed += 100

        $master  = MasterClient::create(['name' => 'Cliente Remaining']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Remaining', 'tax_id' => '155-3333-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Remaining']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'proposal',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'amount' => 100000, 'stage_changed_at' => now(), // weighted_pipeline += 70,000
        ]);

        $after = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/reports/summary?scope=team')
            ->assertStatus(200)
            ->json('forecast');

        // El pipeline ponderado subió ~70,000 pero "remaining" solo reacciona a "closed" (+100).
        $this->assertEqualsWithDelta(70000, $after['weighted_pipeline'] - $before['weighted_pipeline'], 0.01);
        $this->assertEqualsWithDelta(-100, $after['remaining'] - $before['remaining'], 0.01);
        $this->assertFalse($after['meta_reached']);
    }
}
