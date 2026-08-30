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

class PipelineControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // makeCard() crea fixtures directo por Eloquent (todavía no existe POST
        // /pipeline) — getTable() de los modelos de Ventas & Diseño necesita un
        // tenant activo para resolver el schema calificado, igual que hace cada
        // request HTTP vía AuthenticateJwt (que lo activa y lo olvida por request).
        // Se activa una sola vez acá porque los fixtures siempre se crean antes de
        // la primera llamada HTTP de cada test.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoEdit(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $permissions = array_merge(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'], $extra);
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCard(int $ownerId, string $stage = 'lead', ?Carbon $stageChangedAt = null, ?string $tag = null): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente Test',
            'tax_id'            => '155-0000-1-2026',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test', 'tag' => $tag]);

        return PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => $stage,
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'stage_changed_at' => $stageChangedAt ?? Carbon::now(),
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_listar_pipeline_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/pipeline')->assertStatus(401);
    }

    public function test_listar_pipeline_sin_permiso_retorna_403(): void
    {
        // designer@illuminations.test (role_id=vendedor_disenador) YA tiene acceso real desde el
        // puente de vocabulario (2026-07-10, ver User::effectivePermissionsV2()) sin necesitar
        // extra_permissions -- para probar el 403 real hace falta alguien sin rol mapeado y sin
        // grant individual. supervisor@illuminations.test (role_key=null en AuthUserSeeder) sigue
        // sin ningún acceso a Ventas & Diseño por ninguna vía.
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!', 'supervisor');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(403);
    }

    /**
     * Resuelto con Luis 2026-07-10 (Senior Review): el rol solo (sin extra_permissions
     * individual) ya otorga acceso real a la ruta, cumpliendo el criterio de aceptación original
     * de SCRUM-144/145 ("sin tocar código"/sin asignar permisos a mano por persona).
     */
    public function test_disenador_con_rol_pero_sin_extra_permissions_ya_tiene_acceso(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);
    }

    public function test_disenador_solo_ve_sus_propias_tarjetas(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');

        $mine   = $this->makeCard($designerId);
        $theirs = $this->makeCard($managerId);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_gerencia_ve_todas_las_tarjetas_con_alcance_equipo(): void
    {
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        // management@illuminations.test ya tiene superadmin en el seed demo,
        // así que pasa CheckPermission sin necesidad de grant explícito.
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?scope=team')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card1->id));
        $this->assertTrue($ids->contains($card2->id));
    }

    public function test_gerencia_con_alcance_equipo_puede_acotar_a_un_vendedor_especifico(): void
    {
        // Grupo 3 (REQ-056): dentro de "Equipo", Líder/Gerencia puede filtrar a un
        // vendedor puntual — reusado por la pantalla Inicio, ver HomeControllerTest.
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline?scope=team&owner_id={$designerId}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card1->id));
        $this->assertFalse($ids->contains($card2->id));
    }

    public function test_tarjeta_estancada_se_marca_is_stagnant(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $stagnant = $this->makeCard($designerId, 'design', Carbon::now()->subDays(20));
        $fresh    = $this->makeCard($designerId, 'lead', Carbon::now()->subDays(2));

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);

        $data = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($data[$stagnant->id]['is_stagnant']);
        $this->assertFalse($data[$fresh->id]['is_stagnant']);
    }

    public function test_chip_estancados_filtra_solo_tarjetas_con_mas_de_15_dias(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $stagnant = $this->makeCard($designerId, 'design', Carbon::now()->subDays(16));
        $fresh    = $this->makeCard($designerId, 'lead', Carbon::now()->subDays(2));

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?chip=stagnant')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($stagnant->id));
        $this->assertFalse($ids->contains($fresh->id));
    }

    public function test_chip_final_stage_filtra_solo_etapa_propuesta(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $proposal = $this->makeCard($designerId, 'proposal');
        $lead     = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?chip=final_stage')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($proposal->id));
        $this->assertFalse($ids->contains($lead->id));
    }

    /**
     * SCRUM-796 (secc. 1.1/1.2) — Dashboard CRM (indicadores + gráfica "Proyectos
     * por etapa") navega acá con `?stage=<etapa>`, filtro literal por
     * PipelineCard.stage, independiente de `chip`. Cubre las 2 etapas que `chip`
     * no soporta hoy (lead/lost) para confirmar que el filtro es genérico y no
     * quedó atado a las mismas 2 etapas que ya tenía `chip`.
     */
    public function test_stage_filtra_por_etapa_literal_incluidas_lead_y_lost(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $lead = $this->makeCard($designerId, 'lead');
        $lost = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $responseLead = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?stage=lead')
            ->assertStatus(200);
        $idsLead = collect($responseLead->json('data'))->pluck('id');
        $this->assertTrue($idsLead->contains($lead->id));
        $this->assertFalse($idsLead->contains($lost->id));

        $responseLost = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?stage=lost')
            ->assertStatus(200);
        $idsLost = collect($responseLost->json('data'))->pluck('id');
        $this->assertTrue($idsLost->contains($lost->id));
        $this->assertFalse($idsLost->contains($lead->id));
    }

    public function test_stage_con_valor_invalido_se_ignora_sin_error(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?stage=no-existe')
            ->assertStatus(200);

        // Un valor fuera del enum se descarta silenciosamente (mismo criterio que el
        // resto de los filtros de este endpoint) — nunca filtra por una etapa inexistente.
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card->id));
    }

    /**
     * SCRUM-796 (secc. 1.3) — Dashboard "Por tipo de solicitud" navega acá con
     * `?tag=design|quote|both` (mismo enum de SalesProject::tag). "both" ya
     * representa "Diseño + Cotización" como su propio valor, no requiere combinar
     * dos filtros.
     */
    public function test_tag_filtra_por_etiqueta_del_proyecto(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $design   = $this->makeCard($designerId, 'lead', tag: 'design');
        $quote    = $this->makeCard($designerId, 'lead', tag: 'quote');
        $both     = $this->makeCard($designerId, 'lead', tag: 'both');
        $untagged = $this->makeCard($designerId, 'lead', tag: null);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?tag=both')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($design->id));
        $this->assertFalse($ids->contains($quote->id));
        $this->assertFalse($ids->contains($untagged->id));
    }

    // ── store (Grupo 1d, REQ-019/020) ────────────────────────────────────────

    public function test_crear_pipeline_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'X'])->assertStatus(401);
    }

    public function test_crear_pipeline_sin_permiso_write_retorna_403(): void
    {
        // supervisor@ (role_key=null) no tiene ningún alcance de rol sobre ventas_diseno -- a
        // diferencia de designer@ (vendedor_disenador real, nivel 4 desde SCRUM-83), así que un
        // extra_permissions de solo lectura no queda pisado por el bridge rol+nivel.
        $this->grantVentasDisenoRead('supervisor@illuminations.test');
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Proyecto Nuevo'])
            ->assertStatus(403);
    }

    /**
     * SCRUM-83 — Marly probó con la cuenta real del actor (vendedor/diseñador) y no podía crear
     * proyectos ni cotizaciones (403), pese a que el rol solo ya debería alcanzar (ver docblock
     * de User::effectivePermissionsV2()). Sin ningún grant manual de extra_permissions -- a
     * diferencia de los demás tests de este archivo, que llaman grantVentasDisenoEdit() y por eso
     * nunca ejercitaron el bridge rol+nivel real. Root cause: designer@ estaba seedeado en nivel
     * 3, que no tiene fila en security_level_module_permissions (solo 1/4/8/9 la tienen desde la
     * corrección de ADR-006) -- corregido a nivel 4, el default real de vendedor_disenador.
     */
    public function test_disenador_crea_pipeline_solo_con_su_rol_seedeado_sin_grant_manual(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Lead SCRUM-83'])
            ->assertStatus(201);
    }

    public function test_crear_lead_sin_cliente_queda_en_columna_lead_con_cliente_vacio(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Lead Manual'])
            ->assertStatus(201);

        $response->assertJsonPath('stage', 'lead');
        $response->assertJsonPath('sales_project.name', 'Lead Manual');
        $response->assertJsonPath('master_client', null);
        $response->assertJsonPath('sub_client', null);
        $response->assertJsonPath('owner.id', $designerId);
    }

    public function test_crear_lead_con_tag_y_observations_los_persiste(): void
    {
        // SCRUM-88 (REQ-019) — observations existía en $fillable de PipelineCard y en la
        // columna real, pero StorePipelineCardRequest/PipelineService::create() nunca lo
        // aceptaban en el alta manual desde el modal "+ Nuevo Proyecto".
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', [
                'type'         => 'lead',
                'name'         => 'Lead con datos',
                'tag'          => 'quote',
                'observations' => 'Cliente pidió cotización urgente',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('sales_project.tag', 'quote');
        $response->assertJsonPath('observations', 'Cliente pidió cotización urgente');
    }

    public function test_crear_diseno_sin_sub_client_id_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'design', 'name' => 'Proyecto Diseño'])
            ->assertStatus(422);
    }

    public function test_crear_diseno_vinculado_a_subcliente_existente_hereda_master_client_y_nace_en_diseno(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');

        $master = MasterClient::create(['name' => 'Cliente Existente']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente Existente',
            'tax_id'            => '155-1111-1-2026',
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', [
                'type' => 'design', 'name' => 'Proyecto Diseño', 'sub_client_id' => $sub->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('stage', 'design');
        $response->assertJsonPath('master_client.id', $master->id);
        $response->assertJsonPath('sub_client.id', $sub->id);

        // REQ-020: una única tarjeta final en Diseño, sin duplicado en Lead. El
        // request anterior "olvidó" el tenant activo al terminar (ver nota en
        // setUp()), así que se reactiva antes de esta consulta directa por Eloquent.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(1, PipelineCard::where('sub_client_id', $sub->id)->count());
    }

    public function test_crear_con_tipo_invalido_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'approved', 'name' => 'X'])
            ->assertStatus(422);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_ver_detalle_incluye_contactos_fechas_y_aviso_de_arquitecto(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);
        $card->contacts()->create(['name' => 'Cliente Uno', 'role' => 'client', 'phone' => '6000-0000']);
        $card->deliveryDates()->create(['date' => '2026-08-01']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(200);

        $response->assertJsonCount(1, 'contacts');
        $response->assertJsonPath('contacts.0.name', 'Cliente Uno');
        $response->assertJsonPath('delivery_dates.0', '2026-08-01');
        $response->assertJsonPath('delivery_type', null);
        $response->assertJsonPath('has_architect_contact', false);
        $response->assertJsonPath('can_edit', true);
        $response->assertJsonPath('files', []);
    }

    public function test_ver_detalle_de_tarjeta_ajena_sin_alcance_equipo_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $managerId = $this->userIdByEmail('management@illuminations.test');
        $card = $this->makeCard($managerId);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_actualizar_nombre_etiqueta_y_observaciones(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'name'         => 'Proyecto Renombrado',
                'tag'          => 'design',
                'observations' => 'Cliente pidió acabado mate',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('sales_project.name', 'Proyecto Renombrado');
        $response->assertJsonPath('sales_project.tag', 'design');
        $response->assertJsonPath('observations', 'Cliente pidió acabado mate');
    }

    public function test_actualizar_superficie_negativa_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['worked_area_m2' => -50])
            ->assertStatus(422);
    }

    /**
     * SCRUM-81 — worked_area_m2 es decimal(10,2) en la migración; sin un `max:` que
     * coincida, un valor que desborda la columna llegaba intacto a Postgres y producía
     * un 500 sin manejar en vez de un 422 (reportado por Marly con 999999999).
     */
    public function test_actualizar_superficie_fuera_de_rango_retorna_422_no_500(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['worked_area_m2' => 999999999])
            ->assertStatus(422);
    }

    public function test_actualizar_reemplaza_fechas_de_entrega(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);
        $card->deliveryDates()->create(['date' => '2026-01-01']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'partial',
                'delivery_dates' => ['2026-09-01', '2026-09-15'],
            ])
            ->assertStatus(200);

        $response->assertJsonCount(2, 'delivery_dates');
        $this->assertSame(['2026-09-01', '2026-09-15'], $response->json('delivery_dates'));
    }

    public function test_vincular_subcliente_de_otro_master_client_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId);

        $otherMaster = MasterClient::create(['name' => 'Otro Cliente']);
        $otherSub    = SubClient::create([
            'master_client_id' => $otherMaster->id,
            'business_name'    => 'Otro Subcliente',
            'tax_id'            => '155-9999-1-2026',
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['sub_client_id' => $otherSub->id])
            ->assertStatus(422);
    }

    public function test_no_se_puede_editar_tarjeta_en_etapa_lost(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'intento de edición'])
            ->assertStatus(403);
    }

    public function test_no_se_puede_editar_tarjeta_aprobada_sin_permiso_elevado(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'intento de edición'])
            ->assertStatus(403);
    }

    public function test_se_puede_editar_tarjeta_aprobada_con_permiso_elevado(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'Mark editando'])
            ->assertStatus(200);
    }

    public function test_actualizar_sin_permiso_edit_retorna_403(): void
    {
        // Ver comentario de test_crear_pipeline_sin_permiso_write_retorna_403 -- supervisor@ en
        // vez de designer@ (SCRUM-83).
        $this->grantVentasDisenoRead('supervisor@illuminations.test');
        $supervisorId = $this->userIdByEmail('supervisor@illuminations.test');
        $card = $this->makeCard($supervisorId);

        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'x'])
            ->assertStatus(403);
    }

    // ── uploadFile (REQ-013) ─────────────────────────────────────────────────

    public function test_subir_archivo_de_diseno_en_etapa_diseno(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'design',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    public function test_subir_archivo_de_diseno_en_aprobado_retorna_422_sin_excepcion(): void
    {
        // SCRUM-82 / REQ-013 — a diferencia de otros campos, esto NO tiene excepción
        // vía ventas_diseno.edit.approved: los archivos de diseño quedan de solo
        // lectura una vez Aprobado, sin importar el permiso.
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'design',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_subir_comprobante_de_aprobacion_en_aprobado_con_permiso_elevado_si_funciona(): void
    {
        // El gate incondicional de REQ-013/015 es específico de "design"/"proposal" — otras
        // categorías de archivo siguen la regla general (isEditableBy + permiso).
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'approval_proof',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    public function test_subir_foto_en_etapa_lead(): void
    {
        // SCRUM-88 (REQ-019) Pre-QA CRÍTICO 2026-07-29 — 'photo' se agregó al Rule::in()
        // del controller y al PipelineFileType del frontend, pero el check constraint real
        // de Postgres (pipeline_card_files_type_check) nunca se migró: cualquier upload de
        // Foto fallaba 100% del tiempo con el SQLSTATE crudo expuesto en la UI. Ver
        // migración 2026_07_29_100000_add_photo_to_ventas_diseno_pipeline_card_files_type.
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'photo',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
            ])
            ->assertStatus(201);
    }

    // ── uploadFile (REQ-015 / SCRUM-84) ─────────────────────────────────────

    public function test_subir_archivo_de_propuesta_en_etapa_propuesta(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'proposal');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'proposal',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('propuesta.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    public function test_subir_archivo_de_propuesta_en_aprobado_retorna_422_sin_excepcion(): void
    {
        // SCRUM-84 / REQ-015 — mismo tratamiento incondicional que "design" (SCRUM-82):
        // los archivos de propuesta quedan de solo lectura una vez Aprobado, sin importar
        // el permiso ventas_diseno.edit.approved.
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('designer@illuminations.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'proposal',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('propuesta.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }
}
