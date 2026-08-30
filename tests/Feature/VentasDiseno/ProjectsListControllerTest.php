<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-690/691/693 (REQ-610/611/613, Batch D). Escenarios 1-3 de REQ-610, filtros de REQ-611,
 * columnas exactas del CSV de REQ-613. Mismo patrón de fixtures que PipelineControllerTest.
 */
class ProjectsListControllerTest extends FeatureTestCase
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

    /** @param array{name?: string, tag?: string|null, amount?: float|null, worked_area_m2?: float|null} $projectOverrides */
    private function makeCard(
        int $ownerId,
        string $stage = 'lead',
        ?Carbon $stageChangedAt = null,
        array $projectOverrides = [],
        ?float $amount = null,
        ?float $workedArea = null,
    ): PipelineCard {
        $master = MasterClient::create(['name' => $projectOverrides['master_name'] ?? 'Cliente Test']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente Test',
            'tax_id'            => '155-0000-1-2026',
        ]);
        $project = SalesProject::create([
            'sub_client_id' => $sub->id,
            'name'          => $projectOverrides['name'] ?? 'Proyecto Test',
            'tag'           => $projectOverrides['tag'] ?? null,
        ]);

        return PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => $stage,
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'amount'           => $amount,
            'worked_area_m2'   => $workedArea,
            'stage_changed_at' => $stageChangedAt ?? Carbon::now(),
        ]);
    }

    // ── Escenario 1 — Vendedor ve solo lo suyo (REQ-610 RN1) ────────────────────

    public function test_vendedor_solo_ve_sus_propios_proyectos(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $mine   = $this->makeCard($designerId);
        $theirs = $this->makeCard($managerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertFalse($response->json('can_view_team'));
    }

    // ── Escenario 2 — Líder/Gerencia ve todo (REQ-610 RN2) ───────────────────────

    public function test_gerencia_ve_los_proyectos_de_todo_el_equipo(): void
    {
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?scope=team')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card1->id));
        $this->assertTrue($ids->contains($card2->id));
        $this->assertTrue($response->json('can_view_team'));
        $this->assertNotEmpty($response->json('filters.owners'));
    }

    public function test_gerencia_puede_acotar_a_un_responsable_especifico(): void
    {
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/projects?scope=team&owner_id={$designerId}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card1->id));
        $this->assertFalse($ids->contains($card2->id));
    }

    // ── Escenario 3 — Datos completos por fila (REQ-610 RN3/RN6) ─────────────────

    public function test_fila_incluye_los_datos_esperados_y_null_cuando_falta(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $lead = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $lead->id);
        $this->assertNotNull($row);
        $this->assertSame('lead', $row['stage']);
        // RN6: Valor/Superficie sin definir -> null (el frontend renderiza "—").
        $this->assertNull($row['amount']);
        $this->assertNull($row['worked_area_m2']);
        $this->assertNull($row['next_delivery_date']);
        $this->assertSame(0, $row['files_count']);
        $this->assertSame(0, $row['days_in_stage']);
    }

    public function test_proxima_fecha_de_entrega_pendiente_se_prioriza_sobre_fechas_vencidas(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $card = $this->makeCard($designerId, 'approved');
        $card->deliveryDates()->create(['date' => Carbon::today()->subDays(10)]);
        $card->deliveryDates()->create(['date' => Carbon::today()->addDays(5)]);
        $card->deliveryDates()->create(['date' => Carbon::today()->addDays(20)]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $card->id);
        $this->assertSame(Carbon::today()->addDays(5)->toDateString(), $row['next_delivery_date']);
    }

    public function test_si_todas_las_fechas_ya_vencieron_muestra_la_mas_reciente(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $card = $this->makeCard($designerId, 'approved');
        $card->deliveryDates()->create(['date' => Carbon::today()->subDays(20)]);
        $card->deliveryDates()->create(['date' => Carbon::today()->subDays(3)]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $card->id);
        $this->assertSame(Carbon::today()->subDays(3)->toDateString(), $row['next_delivery_date']);
    }

    // ── REQ-611 — Filtros y buscador ──────────────────────────────────────────

    public function test_busqueda_de_texto_filtra_por_nombre_de_proyecto_o_cliente(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $match     = $this->makeCard($designerId, 'lead', null, ['name' => 'Torre Marbella']);
        $otherName = $this->makeCard($designerId, 'lead', null, ['name' => 'Residencial Costa']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?search=marbella')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($otherName->id));
    }

    public function test_filtro_de_etapa_acota_la_tabla(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $lead   = $this->makeCard($designerId, 'lead');
        $design = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?stage=design')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($design->id));
        $this->assertFalse($ids->contains($lead->id));
    }

    public function test_filtro_de_etiqueta_acota_la_tabla(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $both   = $this->makeCard($designerId, 'lead', null, ['tag' => 'both']);
        $design = $this->makeCard($designerId, 'lead', null, ['tag' => 'design']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?tag=both')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($design->id));
    }

    public function test_filtro_de_responsable_no_tiene_efecto_para_vendedor(): void
    {
        // RN4: sin scope=team, owner_id se ignora — el vendedor ya está limitado a lo suyo.
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $mine = $this->makeCard($designerId);
        $this->makeCard($managerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/projects?owner_id={$managerId}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);
    }

    // Pre-QA 2026-07-31 (Batch D, SCRUM-690/691) — cierra el hueco de cobertura que Senior
    // Review dejó registrado como sugerencia 🟡 #4: el código ya ignoraba scope=team/owner_id
    // forzados a mano por un Vendedor (verificado manualmente vía curl contra el backend real
    // durante esta sesión de Pre-QA, sin fuga), pero la suite normal solo probaba el caso donde
    // el frontend simplemente no manda esos parámetros. Este test deja el bypass adversarial
    // cubierto de forma permanente, no solo verificado ad-hoc.
    public function test_vendedor_no_puede_forzar_scope_team_ni_owner_id_de_otro_mandandolo_directo(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $mine       = $this->makeCard($designerId);
        $theirs     = $this->makeCard($managerId, 'lead', null, ['name' => 'Proyecto Ajeno Del Manager']);
        $theirsName = $theirs->fresh()->salesProject->name;

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        // Intento de bypass: forzar scope=team Y owner_id del manager directo en el query string,
        // sin pasar por ningún control de UI.
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/projects?scope=team&owner_id={$managerId}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertCount(1, $ids);
        $this->assertFalse($response->json('can_view_team'));

        // Mismo intento contra /export — el CSV tampoco debe filtrar por el owner_id ajeno.
        $csv = $this->withHeaders($this->authHeaders($token))
            ->get("/api/ventas-diseno/projects/export?scope=team&owner_id={$managerId}");
        $csv->assertStatus(200);
        $content = $csv->streamedContent();
        $this->assertStringNotContainsString($theirsName, $content);
    }

    // ── Paginación ────────────────────────────────────────────────────────────

    // RN6 (REQ-611): "X proyectos" (filtrado, meta.total) vs "Y en total" (total_unfiltered,
    // mismo scope pero ignorando search/stage/tag) son dos números distintos.
    public function test_total_unfiltered_ignora_filtros_de_busqueda_pero_respeta_el_scope(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $this->makeCard($designerId, 'lead', null, ['name' => 'Torre Marbella']);
        $this->makeCard($designerId, 'design', null, ['name' => 'Otro Proyecto']);
        $this->makeCard($managerId); // fuera de scope 'own' del designer

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?search=marbella')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.total'));
        // total_unfiltered: 2 (sus 2 proyectos), no 3 (el del manager queda fuera del scope 'own').
        $this->assertSame(2, $response->json('total_unfiltered'));
    }

    public function test_listado_pagina_desde_el_backend(): void
    {
        // SCRUM-754: 5 ya no es una opción válida de per_page (10/20/50/100/todas) — se usa 10.
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        for ($i = 0; $i < 12; $i++) {
            $this->makeCard($designerId);
        }

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects?per_page=10')
            ->assertStatus(200);

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(12, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    // ── REQ-613 — Exportar CSV ───────────────────────────────────────────────

    public function test_exportar_csv_respeta_los_filtros_aplicados(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $this->makeCard($designerId, 'lead', null, ['name' => 'Proyecto Filtrado']);
        $this->makeCard($designerId, 'design', null, ['name' => 'Proyecto Excluido']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/ventas-diseno/projects/export?stage=lead');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Proyecto Filtrado', $content);
        $this->assertStringNotContainsString('Proyecto Excluido', $content);
    }

    public function test_exportar_csv_tiene_exactamente_las_10_columnas_visibles(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $this->makeCard($designerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/ventas-diseno/projects/export');

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // BOM UTF-8 al inicio (Excel).
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines  = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $content)));
        $header = str_getcsv($lines[0]);
        $this->assertCount(10, $header);
        $this->assertSame(
            ['Proyecto', 'Cliente', 'Etapa', 'Etiqueta', 'Responsable', 'Valor', 'Superficie trabajada', 'Días en etapa', 'Fecha de entrega', 'Archivos'],
            $header,
        );
    }

    public function test_exportar_csv_sin_autenticar_retorna_401(): void
    {
        $this->get('/api/ventas-diseno/projects/export')->assertStatus(401);
    }

    public function test_listar_proyectos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/projects')->assertStatus(401);
    }

    public function test_listar_proyectos_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('supervisor@atlantic.test', 'Password123!', 'supervisor');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/projects')
            ->assertStatus(403);
    }
}
