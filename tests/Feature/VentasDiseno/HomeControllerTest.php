<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Grupo 3 del plan de PM — pantalla Inicio (REQ-056, 057, 059, 060). REQ-058 tiene
 * su propio tratamiento, ver memory/project_backlog_ventas_diseno_req.md.
 */
class HomeControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // SCRUM-66 — summary() ahora llama a OutlookCalendarService (events_today_count).
        $this->fakeOutlookCalendar();
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

    // Nombre de proyecto único por llamada: el seeder demo (VentasDisenoDemoSeeder)
    // también corre en 'testing' y deja una tarjeta legítimamente estancada (20 días)
    // a nombre del mismo vendedor@atlantic.test — las aserciones de estos tests
    // deben acotarse a SU PROPIA tarjeta por nombre de proyecto, nunca al total de la
    // respuesta ni al primer resultado de un tipo.
    private function makeCard(int $ownerId, string $stage = 'lead', ?Carbon $stageChangedAt = null, bool $withArchitect = false, string $projectName = 'Proyecto Test'): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente Test',
            'tax_id'            => '155-0000-1-2026',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName]);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => $stage,
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'stage_changed_at' => $stageChangedAt ?? Carbon::now(),
        ]);

        if ($withArchitect) {
            PipelineCardContact::create([
                'pipeline_card_id' => $card->id, 'name' => 'Arq. Test', 'role' => 'architect', 'phone' => '6000-0000',
            ]);
        }

        return $card;
    }

    // ── GET /home/vendors — REQ-056 ─────────────────────────────────────────

    public function test_vendors_retorna_lista_vacia_para_disenador(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/vendors')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_vendors_retorna_lista_para_gerencia(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/vendors')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Vendedor Demo'));
    }

    // ── GET /home/summary — Final Stage (REQ-060) ───────────────────────────

    public function test_summary_final_stage_incluye_tarjetas_en_propuesta(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeCard($designerId, 'proposal');
        $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('final_stage'));
    }

    public function test_summary_final_stage_incluye_dias_en_etapa_y_valor(): void
    {
        // SCRUM-68 — Gerencia encontró que el modal de detalle solo traía Subcliente y
        // Observaciones, sin contexto de etapa/días/valor que pide el criterio.
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal', \Carbon\Carbon::now()->subDays(3));
        $card->update(['amount' => 4275.5]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $item = $response->json('final_stage.0');
        $this->assertSame(3, $item['days_in_stage']);
        $this->assertEquals(4275.5, $item['amount']);
    }

    // ── GET /home/summary — Pendientes derivados (REQ-059) ──────────────────

    public function test_summary_pendientes_incluye_tarjeta_sin_contacto_mas_de_7_dias(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeCard($designerId, 'design', Carbon::now()->subDays(10), withArchitect: true, projectName: 'Proyecto 7 Dias');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $pendientes = collect($response->json('pendientes'));
        $noContact = $pendientes->first(fn (array $p) => $p['type'] === 'no_contact' && $p['project'] === 'Proyecto 7 Dias');
        $this->assertNotNull($noContact);
        $this->assertSame('medium', $noContact['priority']);
    }

    public function test_summary_pendientes_marca_prioridad_alta_pasados_14_dias(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeCard($designerId, 'design', Carbon::now()->subDays(20), withArchitect: true, projectName: 'Proyecto 20 Dias');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $noContact = collect($response->json('pendientes'))
            ->first(fn (array $p) => $p['type'] === 'no_contact' && $p['project'] === 'Proyecto 20 Dias');
        $this->assertSame('high', $noContact['priority']);
    }

    public function test_summary_pendientes_incluye_tarjeta_sin_contacto_arquitecto(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeCard($designerId, 'design', Carbon::now(), withArchitect: false, projectName: 'Proyecto Sin Arquitecto');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $pendientes = collect($response->json('pendientes'));
        $this->assertNotNull(
            $pendientes->first(fn (array $p) => $p['type'] === 'missing_architect' && $p['project'] === 'Proyecto Sin Arquitecto')
        );
    }

    public function test_summary_sin_pendientes_para_tarjeta_reciente_y_con_arquitecto(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $this->makeCard($designerId, 'design', Carbon::now(), withArchitect: true, projectName: 'Proyecto Reciente Completo');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        // No se puede aserir count(0) global: el seeder demo deja una tarjeta
        // legítimamente estancada a nombre del mismo designer (ver nota en makeCard).
        // Se verifica en cambio que NINGÚN pendiente referencia a esta tarjeta puntual.
        $pendientes = collect($response->json('pendientes'));
        $this->assertNull($pendientes->first(fn (array $p) => $p['project'] === 'Proyecto Reciente Completo'));
    }

    // ── GET /home/summary — performance (REQ-057) ───────────────────────────

    public function test_summary_performance_calcula_avance_y_comision_del_mes_en_curso(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        SalesGoal::create(['user_id' => $designerId, 'goal_amount' => 10000]);

        $card = $this->makeCard($designerId, 'approved', Carbon::now(), projectName: 'Proyecto Aprobado Mes');
        $card->update(['amount' => 4000]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $performance = $response->json('performance');
        $this->assertEquals(10000.0, $performance['goal_amount']);
        $this->assertEquals(4000.0, $performance['current_amount']);
        $this->assertEquals(40.0, $performance['progress_percent']);
        // $4,000 cae en el primer escalón (< $20,000) => 1.5%
        $this->assertEquals(60.0, $performance['commission_amount']);
    }

    public function test_summary_performance_sin_meta_configurada_es_null(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary')
            ->assertStatus(200);

        $performance = $response->json('performance');
        $this->assertNull($performance['goal_amount']);
        $this->assertNull($performance['progress_percent']);
    }

    public function test_summary_performance_sigue_al_vendedor_seleccionado_por_gerencia(): void
    {
        // SCRUM-64 (AC 3): Gerencia eligiendo un vendedor específico en "Equipo" debe
        // ver la meta/avance/comisión de ESE vendedor en "Mi desempeño", no la propia
        // — corregido 2026-07-12, antes performance() ignoraba $filters a propósito.
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        SalesGoal::create(['user_id' => $managerId, 'goal_amount' => 5000]);
        SalesGoal::create(['user_id' => $designerId, 'goal_amount' => 8000]);

        $card = $this->makeCard($designerId, 'approved', Carbon::now(), projectName: 'Proyecto De Otro Vendedor');
        $card->update(['amount' => 4000]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/home/summary?scope=team&owner_id={$designerId}")
            ->assertStatus(200);

        $performance = $response->json('performance');
        $this->assertEquals(8000.0, $performance['goal_amount']);
        $this->assertEquals(4000.0, $performance['current_amount']);
        $this->assertEquals(50.0, $performance['progress_percent']);
        $this->assertEquals(60.0, $performance['commission_amount']);
    }

    public function test_summary_performance_es_del_propio_usuario_cuando_no_hay_vendedor_elegido(): void
    {
        // "Equipo completo" (scope=team sin owner_id) no tiene un único vendedor al
        // que atribuirle "Mi desempeño" — se mantiene la del usuario autenticado.
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        SalesGoal::create(['user_id' => $managerId, 'goal_amount' => 5000]);

        $card = $this->makeCard($designerId, 'approved', Carbon::now(), projectName: 'Proyecto De Otro Vendedor 2');
        $card->update(['amount' => 9999]);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/home/summary?scope=team')
            ->assertStatus(200);

        $performance = $response->json('performance');
        $this->assertEquals(5000.0, $performance['goal_amount']);
        $this->assertEquals(0.0, $performance['current_amount']);
    }

    // ── GET /home/summary — owner_id (REQ-056) ──────────────────────────────

    public function test_summary_owner_id_filtra_a_vendedor_especifico_para_gerencia(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($designerId, 'proposal');
        $this->makeCard($managerId, 'proposal');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/home/summary?scope=team&owner_id={$designerId}")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('final_stage'));
    }

    public function test_summary_owner_id_es_ignorado_para_disenador(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($designerId, 'proposal');
        $this->makeCard($managerId, 'proposal');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/home/summary?scope=team&owner_id={$managerId}")
            ->assertStatus(200);

        // Un Diseñador nunca ve "Equipo" — owner_id no lo saca de su propio alcance.
        $this->assertCount(1, $response->json('final_stage'));
    }
}
