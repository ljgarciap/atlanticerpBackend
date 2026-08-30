<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * Dashboard CRM (SCRUM-684→689, REQ-604 a REQ-609, Batch C).
 */
class DashboardControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
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

    // Nombre de proyecto único por llamada: el seeder demo (VentasDisenoDemoSeeder) también
    // corre en 'testing' y deja tarjetas propias — las aserciones de este archivo filtran
    // SIEMPRE por su propio project_name, nunca por conteos globales de la respuesta.
    private function makeCard(
        int $ownerId,
        string $stage = 'lead',
        ?Carbon $stageChangedAt = null,
        ?float $amount = null,
        ?string $tag = null,
        string $projectName = 'Proyecto Dashboard Test',
        ?int $masterClientId = null,
    ): PipelineCard {
        $master = $masterClientId !== null
            ? MasterClient::find($masterClientId)
            : MasterClient::create(['name' => "Cliente {$projectName}"]);
        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => "Subcliente {$projectName}",
            'tax_id'            => '155-'.random_int(1000, 9999).'-1-2026',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName, 'tag' => $tag]);

        return PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => $stage,
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'amount'           => $amount,
            'stage_changed_at' => $stageChangedAt ?? Carbon::now(),
        ]);
    }

    // ── REQ-609: acceso restringido a Gerencia ──────────────────────────────

    public function test_designer_no_puede_acceder_al_dashboard(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(403);
    }

    public function test_management_puede_acceder_al_dashboard(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);
    }

    public function test_superadmin_puede_acceder_al_dashboard(): void
    {
        $this->grantVentasDisenoRead('superadmin@atlantic.test');
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);
    }

    // ── REQ-605: tarjetas de conteo por etapa + totales ─────────────────────

    public function test_stage_counts_y_totales_reflejan_los_datos_reales(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');

        $this->makeCard($managerId, 'quote', amount: 1000, projectName: 'REQ605 Cotizacion');
        $this->makeCard($managerId, 'proposal', amount: 2000, projectName: 'REQ605 Propuesta');
        $this->makeCard($managerId, 'approved', amount: 5000, projectName: 'REQ605 Aprobado');
        $this->makeCard($managerId, 'lost', amount: 9999, projectName: 'REQ605 Perdido');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        // No se puede aserir el conteo global (seeder demo deja tarjetas propias) — se
        // verifica que los 4 conteos de etapa sean como mínimo 1 (la propia) y que el
        // total activo/cerrado incluya el monto de esta tarjeta puntual.
        $counts = $response->json('stage_counts');
        $this->assertGreaterThanOrEqual(1, $counts['quote']);
        $this->assertGreaterThanOrEqual(1, $counts['proposal']);
        $this->assertGreaterThanOrEqual(1, $counts['approved']);
        $this->assertGreaterThanOrEqual(1, $counts['lost']);

        $totals = $response->json('totals');
        // active_pipeline = suma de lead+design+quote+proposal (nunca approved/lost)
        $this->assertGreaterThanOrEqual(3000, $totals['active_pipeline']);
        $this->assertGreaterThanOrEqual(5000, $totals['closed_won']);
    }

    public function test_lost_nunca_se_incluye_en_pipeline_activo_ni_cerrado(): void
    {
        // Aísla la aserción negativa creando el ÚNICO dato posible con un monto
        // improbable de colisionar con el seeder demo, y confirma que ese monto
        // específico no aparece sumado en ninguno de los 2 totales.
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($managerId, 'lost', amount: 123456.78, projectName: 'REQ605 Perdido Aislado');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $totals = $response->json('totals');
        $this->assertLessThan(123456.78, $totals['active_pipeline']);
        $this->assertLessThan(123456.78, $totals['closed_won']);
    }

    // ── REQ-604: alertas dinámicas ───────────────────────────────────────────

    public function test_alerta_propuesta_vencida_lista_proyectos_con_mas_de_15_dias(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(20), projectName: 'REQ604 Vencida');
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(3), projectName: 'REQ604 Reciente');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $overdue = $response->json('alerts.overdue_proposals');
        $this->assertNotNull($overdue);
        $names = array_column($overdue['items'], 'project_name');
        $this->assertContains('REQ604 Vencida', $names);
        $this->assertNotContains('REQ604 Reciente', $names);
    }

    /**
     * SCRUM-796 (secc. 3) — el frontend navega a Pipeline con `?card=<id>`
     * (mecanismo ya existente, ver PipelinePage.tsx), por eso cada fila necesita
     * su propio `card_id` real, no solo el nombre del proyecto.
     */
    public function test_alerta_propuesta_vencida_incluye_card_id_navegable(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $card = $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(20), projectName: 'REQ796 Vencida Con Id');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $overdue = $response->json('alerts.overdue_proposals');
        $row = collect($overdue['items'])->firstWhere('project_name', 'REQ796 Vencida Con Id');
        $this->assertNotNull($row);
        $this->assertSame($card->id, $row['card_id']);
        $this->assertGreaterThanOrEqual(20, $row['days']);
    }

    public function test_sin_propuestas_vencidas_el_aviso_no_aparece(): void
    {
        // Aísla verificando que NINGUNA tarjeta propia esta vez cae en la ventana
        // vencida — no se puede asertar null global porque el seeder demo puede
        // tener sus propias tarjetas estancadas.
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(2), projectName: 'REQ604 Nunca Vencida');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $overdue = $response->json('alerts.overdue_proposals');
        if ($overdue !== null) {
            $this->assertNotContains('REQ604 Nunca Vencida', array_column($overdue['items'], 'project_name'));
        } else {
            $this->assertNull($overdue);
        }
    }

    /**
     * SCRUM-796 (secc. 4, corrección de criterio) — el CONTEO del indicador sigue
     * colapsando por Cliente Master (un cliente con 2 proyectos estancados sigue
     * contando 1 vez), pero la LISTA/modal "Ver más" ya NO colapsa: cada tarjeta
     * estancada es su propia fila, con su propio card_id, para poder navegar a la
     * tarjeta exacta — un cliente con 2 proyectos estancados debe poder elegir
     * cuál de los 2 ir a resolver.
     */
    public function test_clientes_sin_contacto_reciente_conteo_colapsa_pero_lista_no(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $master = MasterClient::create(['name' => 'Cliente REQ604 Duplicado']);

        // 2 tarjetas distintas, mismo Cliente Master, ambas estancadas en etapas activas.
        $cardA = $this->makeCard($managerId, 'design', Carbon::now()->subDays(18), projectName: 'REQ604 Dup A', masterClientId: $master->id);
        $cardB = $this->makeCard($managerId, 'quote', Carbon::now()->subDays(25), projectName: 'REQ604 Dup B', masterClientId: $master->id);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $cold = $response->json('alerts.cold_clients');
        $this->assertNotNull($cold);

        $rowsForClient = collect($cold['items'])->where('client_name', 'Cliente REQ604 Duplicado');
        // La lista trae las 2 tarjetas por separado, cada una con su propio card_id
        // (antes de SCRUM-796 esto colapsaba a 1 sola entrada de texto).
        $this->assertCount(2, $rowsForClient);
        $this->assertEqualsCanonicalizing([$cardA->id, $cardB->id], $rowsForClient->pluck('card_id')->all());
    }

    public function test_clientes_sin_contacto_reciente_excluye_aprobado_y_perdido(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $master = MasterClient::create(['name' => 'Cliente REQ604 Aprobado Aislado']);
        $this->makeCard($managerId, 'approved', Carbon::now()->subDays(30), projectName: 'REQ604 Aprobado Viejo', masterClientId: $master->id);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $cold = $response->json('alerts.cold_clients');
        $clients = $cold['items'] ?? [];
        $this->assertNotContains('Cliente REQ604 Aprobado Aislado', array_column($clients, 'client_name'));
    }

    // ── REQ-607: gráfico de dona por tipo de solicitud ───────────────────────

    public function test_by_tag_excluye_tarjetas_sin_etiqueta_del_conteo(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($managerId, 'quote', tag: 'design', projectName: 'REQ607 Diseno');
        $this->makeCard($managerId, 'quote', tag: 'quote', projectName: 'REQ607 Cotizacion');
        $this->makeCard($managerId, 'quote', tag: 'both', projectName: 'REQ607 Ambos');
        $this->makeCard($managerId, 'quote', tag: null, projectName: 'REQ607 Sin Etiqueta');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/dashboard/summary')
            ->assertStatus(200);

        $byTag = $response->json('by_tag');
        $this->assertGreaterThanOrEqual(1, $byTag['tagged']['design']);
        $this->assertGreaterThanOrEqual(1, $byTag['tagged']['quote']);
        $this->assertGreaterThanOrEqual(1, $byTag['tagged']['both']);
        $this->assertGreaterThanOrEqual(1, $byTag['untagged_count']);
    }

    // ── REQ-604 RN5/RN6: enviar recordatorios ────────────────────────────────

    public function test_remind_notifica_solo_al_responsable_de_sus_propios_proyectos_vencidos(): void
    {
        Mail::fake();
        $this->grantVentasDisenoRead('management@atlantic.test');
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $this->makeCard($designerId, 'proposal', Carbon::now()->subDays(20), projectName: 'REQ604 Remind Designer');
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(20), projectName: 'REQ604 Remind Manager');
        $this->makeCard($designerId, 'proposal', Carbon::now()->subDays(2), projectName: 'REQ604 Remind No Vencida');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/dashboard/remind')
            ->assertStatus(200);

        $notified = collect($response->json('notified'));
        $designerEntry = $notified->firstWhere('owner_id', $designerId);
        $managerEntry  = $notified->firstWhere('owner_id', $managerId);

        $this->assertNotNull($designerEntry);
        $this->assertNotNull($managerEntry);
        // El designer tiene 1 propuesta vencida propia (la de 2 días no cuenta) — nunca
        // recibe la del manager, y viceversa.
        $this->assertSame(1, $designerEntry['projects_count']);

        $this->reMakeCurrent();
        $sendsForDesigner = NotificationSend::where('source', NotificationSend::SOURCE_CRM_DASHBOARD_PROPOSAL_REMINDER)
            ->get()
            ->filter(fn (NotificationSend $s) => in_array($designerId, $s->audience, true));
        $this->assertGreaterThanOrEqual(1, $sendsForDesigner->count());
        foreach ($sendsForDesigner as $send) {
            $this->assertNotContains($managerId, $send->audience);
        }
    }

    public function test_remind_sin_propuestas_vencidas_responde_422(): void
    {
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        // Solo tarjetas recientes propias — no se puede garantizar 0 global por el
        // seeder demo, así que este test solo tiene sentido si el seeder demo NO deja
        // ninguna propuesta vencida propia; se confirma indirectamente vía el 422 en sí
        // (si el seeder alguna vez agrega una propuesta vencida, este test fallaría de
        // forma visible y habría que revisar la nota, no ignorarla).
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(1), projectName: 'REQ604 Remind Nada');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/dashboard/remind')
            ->assertStatus(422);
    }

    /**
     * Hallazgo de Pre-QA (2026-07-31, batch SCRUM-684→689): un segundo clic en "Enviar
     * recordatorios" el mismo día (doble clic, o simplemente volver a entrar y presionarlo de
     * nuevo) mandaba un SEGUNDO email real duplicado al mismo responsable por la misma
     * propuesta vencida — confirmado en dev.atlanticerp.ai con dos llamadas reales ~20s aparte,
     * ambas entregadas (status 'sent'). `source_ref` (fecha+owner) ya tenía forma de clave de
     * idempotencia pero nada la hacía cumplir. Este test fija el comportamiento correcto: la
     * segunda llamada del mismo día no debe crear un NotificationSend nuevo para ese owner.
     */
    public function test_remind_no_duplica_el_envio_si_se_llama_dos_veces_el_mismo_dia(): void
    {
        Mail::fake();
        $this->grantVentasDisenoRead('management@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $this->makeCard($managerId, 'proposal', Carbon::now()->subDays(20), projectName: 'REQ604 Remind Doble Clic');

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $first = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/dashboard/remind')
            ->assertStatus(200);
        $this->assertNotEmpty($first->json('notified'));

        // Segunda llamada, mismo día — no debe volver a notificar a este responsable.
        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/dashboard/remind')
            ->assertStatus(200);
        $this->assertEmpty($second->json('notified') ?? []);
        $this->assertContains($managerId, $second->json('already_sent_today') ?? []);

        $this->reMakeCurrent();
        $sendsForManager = NotificationSend::where('source', NotificationSend::SOURCE_CRM_DASHBOARD_PROPOSAL_REMINDER)
            ->get()
            ->filter(fn (NotificationSend $s) => in_array($managerId, $s->audience, true));
        // Exactamente 1 send para este responsable/día, no 2 — antes del fix, esto daba 2.
        $this->assertCount(1, $sendsForManager);
    }
}
