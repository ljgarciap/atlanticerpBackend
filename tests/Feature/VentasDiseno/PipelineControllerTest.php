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
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoEdit(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $permissions = array_merge(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'], $extra);
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
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
        // vendedor@atlantic.test (role_id=vendedor) YA tiene acceso real desde el
        // puente de vocabulario (2026-07-10, ver User::effectivePermissionsV2()) sin necesitar
        // extra_permissions -- para probar el 403 real hace falta alguien sin rol mapeado y sin
        // grant individual. nivel5@atlantic.test (role_key=null en AuthUserSeeder) sigue
        // sin ningún acceso a Ventas & Diseño por ninguna vía.
        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!', 'supervisor');

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
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);
    }

    public function test_disenador_solo_ve_sus_propias_tarjetas(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $mine   = $this->makeCard($designerId);
        $theirs = $this->makeCard($managerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_gerencia_ve_todas_las_tarjetas_con_alcance_equipo(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        // management@atlantic.test ya tiene superadmin en el seed demo,
        // así que pasa CheckPermission sin necesidad de grant explícito.
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

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
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managerId  = $this->userIdByEmail('management@atlantic.test');

        $card1 = $this->makeCard($designerId);
        $card2 = $this->makeCard($managerId);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline?scope=team&owner_id={$designerId}")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card1->id));
        $this->assertFalse($ids->contains($card2->id));
    }

    public function test_tarjeta_estancada_se_marca_is_stagnant(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $stagnant = $this->makeCard($designerId, 'design', Carbon::now()->subDays(20));
        $fresh    = $this->makeCard($designerId, 'lead', Carbon::now()->subDays(2));

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline')
            ->assertStatus(200);

        $data = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($data[$stagnant->id]['is_stagnant']);
        $this->assertFalse($data[$fresh->id]['is_stagnant']);
    }

    public function test_chip_estancados_filtra_solo_tarjetas_con_mas_de_15_dias(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $stagnant = $this->makeCard($designerId, 'design', Carbon::now()->subDays(16));
        $fresh    = $this->makeCard($designerId, 'lead', Carbon::now()->subDays(2));

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?chip=stagnant')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($stagnant->id));
        $this->assertFalse($ids->contains($fresh->id));
    }

    public function test_chip_final_stage_filtra_solo_etapa_propuesta(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $proposal = $this->makeCard($designerId, 'proposal');
        $lead     = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $lead = $this->makeCard($designerId, 'lead');
        $lost = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');

        $design   = $this->makeCard($designerId, 'lead', tag: 'design');
        $quote    = $this->makeCard($designerId, 'lead', tag: 'quote');
        $both     = $this->makeCard($designerId, 'lead', tag: 'both');
        $untagged = $this->makeCard($designerId, 'lead', tag: null);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        // nivel5@ (role_key=null) no tiene ningún alcance de rol sobre ventas_diseno -- a
        // diferencia de vendedor@ (vendedor real, nivel 4 desde SCRUM-83), así que un
        // extra_permissions de solo lectura no queda pisado por el bridge rol+nivel.
        $this->grantVentasDisenoRead('nivel5@atlantic.test');
        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Proyecto Nuevo'])
            ->assertStatus(403);
    }

    /**
     * SCRUM-83 — Gerencia probó con la cuenta real del actor (vendedor/diseñador) y no podía crear
     * proyectos ni cotizaciones (403), pese a que el rol solo ya debería alcanzar (ver docblock
     * de User::effectivePermissionsV2()). Sin ningún grant manual de extra_permissions -- a
     * diferencia de los demás tests de este archivo, que llaman grantVentasDisenoEdit() y por eso
     * nunca ejercitaron el bridge rol+nivel real. Root cause: vendedor@ estaba seedeado en nivel
     * 3, que no tiene fila en security_level_module_permissions (solo 1/4/8/9 la tienen desde la
     * corrección de ADR-006) -- corregido a nivel 4, el default real de vendedor.
     */
    public function test_disenador_crea_pipeline_solo_con_su_rol_seedeado_sin_grant_manual(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Lead SCRUM-83'])
            ->assertStatus(201);
    }

    public function test_crear_lead_sin_cliente_queda_en_columna_lead_con_cliente_vacio(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'design', 'name' => 'Proyecto Diseño'])
            ->assertStatus(422);
    }

    public function test_crear_diseno_vinculado_a_subcliente_existente_hereda_master_client_y_nace_en_diseno(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');

        $master = MasterClient::create(['name' => 'Cliente Existente']);
        $sub    = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => 'Subcliente Existente',
            'tax_id'            => '155-1111-1-2026',
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, PipelineCard::where('sub_client_id', $sub->id)->count());
    }

    public function test_crear_pipeline_setea_creator_al_usuario_que_crea(): void
    {
        // SCRUM-884 — "Creador" es siempre quien hizo el alta, distinto de "Responsable"
        // (owner), aunque en el caso simple (sin asignación de Gerencia) ambos coincidan.
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Lead Creador'])
            ->assertStatus(201);

        $response->assertJsonPath('owner.id', $designerId);
        $response->assertJsonPath('creator.id', $designerId);
    }

    public function test_gerencia_asigna_proyecto_al_crear_deja_creator_en_quien_crea_no_en_el_asignado(): void
    {
        // SCRUM-884, RN — cuando Gerencia crea una tarjeta y la asigna directo a otra persona
        // (assignee_ids), "Responsable" queda en el asignado pero "Creador" es Gerencia, que
        // fue quien de hecho hizo el alta.
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $managementId = $this->userIdByEmail('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', [
                'type' => 'lead', 'name' => 'Lead asignado al crear', 'assignee_ids' => [$designerId],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('owner.id', $designerId);
        $response->assertJsonPath('creator.id', $managementId);
    }

    public function test_reasignar_responsable_no_modifica_creator(): void
    {
        // SCRUM-884, RN — el Creador es inmutable: reasignar el Responsable (Gerencia) nunca
        // debe tocarlo, sin importar cuántas veces se reasigne.
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Lead reasignable'])
            ->assertStatus(201);
        $cardId = $created->json('id');

        $managementToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($managementToken))
            ->putJson("/api/ventas-diseno/pipeline/{$cardId}", ['assignee_ids' => [$supervisorId]])
            ->assertStatus(200);

        $response->assertJsonPath('owner.id', $supervisorId);
        $response->assertJsonPath('creator.id', $designerId);
    }

    public function test_crear_con_tipo_invalido_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/pipeline', ['type' => 'approved', 'name' => 'X'])
            ->assertStatus(422);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_ver_detalle_incluye_contactos_fechas_y_aviso_de_arquitecto(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId);
        $card->contacts()->create(['name' => 'Cliente Uno', 'role' => 'client', 'phone' => '6000-0000']);
        $card->deliveryDates()->create(['date' => '2026-08-01']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $card = $this->makeCard($managerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_actualizar_nombre_etiqueta_y_observaciones(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['worked_area_m2' => -50])
            ->assertStatus(422);
    }

    /**
     * SCRUM-81 — worked_area_m2 es decimal(10,2) en la migración; sin un `max:` que
     * coincida, un valor que desborda la columna llegaba intacto a Postgres y producía
     * un 500 sin manejar en vez de un 422 (reportado por Gerencia con 999999999).
     */
    public function test_actualizar_superficie_fuera_de_rango_retorna_422_no_500(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['worked_area_m2' => 999999999])
            ->assertStatus(422);
    }

    public function test_actualizar_reemplaza_fechas_de_entrega(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        // SCRUM-821 — delivery_type/delivery_dates ahora exige etapa Propuesta/Aprobado (antes la
        // API lo aceptaba en cualquier etapa, SCRUM-78 solo lo ocultaba en el frontend).
        $card = $this->makeCard($designerId, 'proposal');
        $card->deliveryDates()->create(['date' => '2026-01-01']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'partial',
                'delivery_dates' => ['2026-09-01', '2026-09-15'],
            ])
            ->assertStatus(200);

        $response->assertJsonCount(2, 'delivery_dates');
        $this->assertSame(['2026-09-01', '2026-09-15'], $response->json('delivery_dates'));
    }

    /**
     * Pre-QA (SCRUM-817, 2026-08-31) — el fix de SCRUM-817 es solo de UI: el frontend ya nunca
     * arma un payload inconsistente, pero antes de este fix la API en sí aceptaba
     * delivery_type=single con 2+ fechas sin normalizar ni rechazar (confirmado en vivo contra
     * dev.atlanticerp.ai antes de corregir). El invariante "single ⟹ ≤1 fecha" tiene que valer siempre,
     * sin importar quién arme el request — no solo cuando el cliente es el frontend actual.
     */
    public function test_delivery_type_unica_trunca_fechas_adicionales_aunque_el_payload_las_incluya(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'single',
                'delivery_dates' => ['2026-09-01', '2026-09-15'],
            ])
            ->assertStatus(200);

        $response->assertJsonCount(1, 'delivery_dates');
        $this->assertSame(['2026-09-01'], $response->json('delivery_dates'));
    }

    /** SCRUM-821 — "Parcial" exige mínimo 2 fechas (REQ original), la API antes lo aceptaba con 0 o 1 sin avisar. */
    public function test_delivery_type_parcial_con_menos_de_2_fechas_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'partial',
                'delivery_dates' => ['2026-09-01'],
            ])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'partial',
                'delivery_dates' => [],
            ])
            ->assertStatus(422);
    }

    /**
     * SCRUM-821 — delivery_type/delivery_dates fuera de Cotización/Propuesta/Aprobado (SCRUM-78
     * solo lo ocultaba en el frontend). SCRUM-909 amplió las etapas permitidas de
     * Propuesta/Aprobado a Cotización/Propuesta/Aprobado — Lead y Diseño siguen bloqueadas.
     */
    public function test_delivery_type_fuera_de_cotizacion_propuesta_o_aprobado_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'single',
                'delivery_dates' => ['2026-09-01'],
            ])
            ->assertStatus(422);
    }

    /**
     * SCRUM-909 (rebote 2026-09-14) — Cotización pasa a ser una etapa válida para definir la
     * fecha de entrega: antes de este fix, el campo se veía disponible en Cotización en el
     * frontend pero el guardado siempre rechazaba con 422, un callejón sin salida real.
     */
    public function test_delivery_type_en_cotizacion_permite_guardar_fecha_de_entrega(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'quote');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'delivery_type'  => 'partial',
                'delivery_dates' => ['2026-09-01', '2026-09-15'],
            ])
            ->assertStatus(200);

        $this->assertSame('partial', $response->json('delivery_type'));
        $this->assertSame(['2026-09-01', '2026-09-15'], $response->json('delivery_dates'));
    }

    /** SCRUM-821 — la restricción de etapa no debe bloquear una edición que NO toca delivery_type/delivery_dates. */
    public function test_actualizar_otro_campo_en_lead_no_se_bloquea_por_la_restriccion_de_fechas(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'sin tocar fechas'])
            ->assertStatus(200);
    }

    /**
     * SCRUM-828 — regresión de SCRUM-821: el guard chequeaba array_key_exists(), no el valor. El
     * frontend (guardado de formulario completo) siempre manda delivery_type/delivery_dates junto
     * con el resto de campos, como null/[] cuando el usuario no los tocó (ocultos en Cotización
     * desde SCRUM-78) — no deben bloquear el guardado si vienen vacíos.
     */
    public function test_delivery_type_y_delivery_dates_vacios_en_cotizacion_no_bloquean_el_guardado(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'quote');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", [
                'worked_area_m2'  => 45,
                'delivery_type'   => null,
                'delivery_dates'  => [],
            ])
            ->assertStatus(200)
            ->assertJsonPath('worked_area_m2', 45)
            ->assertJsonPath('delivery_type', null);
    }

    public function test_vincular_subcliente_de_otro_master_client_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId);

        $otherMaster = MasterClient::create(['name' => 'Otro Cliente']);
        $otherSub    = SubClient::create([
            'master_client_id' => $otherMaster->id,
            'business_name'    => 'Otro Subcliente',
            'tax_id'            => '155-9999-1-2026',
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['sub_client_id' => $otherSub->id])
            ->assertStatus(422);
    }

    public function test_no_se_puede_editar_tarjeta_en_etapa_lost(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'intento de edición'])
            ->assertStatus(403);
    }

    public function test_no_se_puede_editar_tarjeta_aprobada_sin_permiso_elevado(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'intento de edición'])
            ->assertStatus(403);
    }

    public function test_se_puede_editar_tarjeta_aprobada_con_permiso_elevado(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'Gerencia editando'])
            ->assertStatus(200);
    }

    public function test_actualizar_sin_permiso_edit_retorna_403(): void
    {
        // Ver comentario de test_crear_pipeline_sin_permiso_write_retorna_403 -- nivel5@ en
        // vez de vendedor@ (SCRUM-83).
        $this->grantVentasDisenoRead('nivel5@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $card = $this->makeCard($supervisorId);

        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['observations' => 'x'])
            ->assertStatus(403);
    }

    // ── SCRUM-849.4 — asignación de Gerencia a uno o varios asignados ───────────

    public function test_gerencia_asigna_a_una_sola_persona_reasigna_el_owner(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['assignee_ids' => [$supervisorId]])
            ->assertStatus(200);

        $response->assertJsonPath('owner.id', $supervisorId);
        $assignees = $response->json('assignees');
        $this->assertCount(1, $assignees);
        $this->assertSame($supervisorId, $assignees[0]['id']);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame([], $card->fresh()->compartido_con);
    }

    public function test_gerencia_asigna_a_varias_personas_deja_al_resto_en_compartido_con(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['assignee_ids' => [$designerId, $supervisorId]])
            ->assertStatus(200);

        $response->assertJsonPath('owner.id', $designerId);
        $this->assertCount(2, $response->json('assignees'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame([$supervisorId], $card->fresh()->compartido_con);
    }

    public function test_asignar_sin_ser_gerencia_se_ignora_en_silencio(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['assignee_ids' => [$supervisorId], 'observations' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('owner.id', $designerId);
    }

    public function test_asignado_secundario_compartido_con_puede_ver_el_detalle(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $this->grantVentasDisenoRead('nivel5@atlantic.test');
        $card = $this->makeCard($designerId);
        $card->update(['compartido_con' => [$supervisorId]]);

        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(200);
    }

    public function test_asignado_secundario_ve_la_tarjeta_en_alcance_propio(): void
    {
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $supervisorId = $this->userIdByEmail('nivel5@atlantic.test');
        $this->grantVentasDisenoRead('nivel5@atlantic.test');
        $card = $this->makeCard($designerId);
        $card->update(['compartido_con' => [$supervisorId]]);

        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pipeline?scope=own')
            ->assertStatus(200);

        $this->assertContains($card->id, collect($response->json('data'))->pluck('id')->all());
    }

    // ── uploadFile (REQ-013) ─────────────────────────────────────────────────

    public function test_subir_archivo_de_diseno_en_etapa_diseno(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/ventas-diseno/pipeline/{$card->id}/files", [
                'type' => 'proposal',
                'file' => \Illuminate\Http\UploadedFile::fake()->create('propuesta.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }
}
