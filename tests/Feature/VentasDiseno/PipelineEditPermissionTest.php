<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Models\User;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1139 — Contabilidad edita el Pipeline con el permiso angosto `ventas_diseno.pipeline.edit` (hermano de
 * `pipeline.read_all`, SCRUM-1128) y los proyectos Aprobados con `ventas_diseno.edit.approved`, SIN abrir ninguna otra
 * ruta de Ventas & Diseño (cotizaciones, asociados...). Sin ese permiso sigue en solo lectura.
 */
class PipelineEditPermissionTest extends FeatureTestCase
{
    private const FELIX = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function sellerId(): int
    {
        return (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');
    }

    private function makeCard(string $stage): PipelineCard
    {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->sellerId(), 'stage_changed_at' => Carbon::now(),
        ]);
    }

    /** @return array<string, string> */
    private function felixHeaders(): array
    {
        $headers = $this->authHeaders($this->loginAs(self::FELIX, self::FELIX, 'lider_admin_contab'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $headers;
    }

    /** @param list<string> $quitar */
    private function quitarPermisosDeFelix(array $quitar): void
    {
        $contabilidad = User::where('email', self::FELIX)->firstOrFail();
        $contabilidad->update(['extra_permissions' => array_values(array_diff($contabilidad->extra_permissions ?? [], $quitar))]);
    }

    /** @return list<array{0: string}> */
    public static function etapasNoAprobadas(): array
    {
        return [['lead'], ['design'], ['quote'], ['proposal']];
    }

    /** @dataProvider etapasNoAprobadas */
    public function test_contabilidad_edita_tarjetas_del_equipo_en_etapas_no_aprobadas(string $stage): void
    {
        $card    = $this->makeCard($stage);
        $headers = $this->felixHeaders();

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', true);

        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['name' => 'Renombrado por Contabilidad'])
            ->assertOk();
    }

    public function test_contabilidad_mueve_etapas_de_tarjetas_del_equipo(): void
    {
        $card = $this->makeCard('lead');

        $this->withHeaders($this->felixHeaders())->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])->assertOk();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('lost', PipelineCard::find($card->id)->stage);
    }

    public function test_contabilidad_ve_la_lista_del_equipo(): void
    {
        $card = $this->makeCard('lead');

        $this->withHeaders($this->felixHeaders())->getJson('/api/ventas-diseno/pipeline?scope=team')
            ->assertOk()->assertJsonFragment(['id' => $card->id]);
    }

    public function test_contabilidad_puede_editar_un_proyecto_aprobado_por_edit_approved(): void
    {
        $card    = $this->makeCard('approved');
        $headers = $this->felixHeaders();

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', true);
    }

    public function test_sin_edit_approved_un_proyecto_aprobado_no_es_editable(): void
    {
        $this->quitarPermisosDeFelix(['ventas_diseno.edit.approved']);
        $card = $this->makeCard('approved');

        $this->withHeaders($this->felixHeaders())->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', false);
    }

    public function test_sin_pipeline_edit_contabilidad_vuelve_a_solo_lectura(): void
    {
        $this->felixComoEnScrum1139();
        $this->quitarPermisosDeFelix(['ventas_diseno.pipeline.edit']);
        $card    = $this->makeCard('design');
        $headers = $this->felixHeaders();

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_edit', false);
        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($headers)->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/contacts", [])->assertStatus(403);
    }

    public function test_pipeline_edit_no_abre_ninguna_ruta_fuera_del_pipeline(): void
    {
        $this->felixComoEnScrum1139();
        $headers = $this->felixHeaders();

        // Cotizaciones y arquitectos/asociados exigen `ventas_diseno.edit`/`write`: el permiso angosto no los desbloquea.
        $this->withHeaders($headers)->putJson('/api/ventas-diseno/quotes/999999', ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($headers)->putJson('/api/ventas-diseno/architects/999999', ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/quotes', [])->assertStatus(403);
    }

    public function test_contabilidad_lee_y_guarda_las_entregas_parciales_de_una_tarjeta_del_equipo(): void
    {
        $card    = $this->makeCard('quote');
        $headers = $this->felixHeaders();

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments")->assertOk();
        // Pasó el permiso y la autorización de la tarjeta: lo que responde ahora es la validación del cuerpo.
        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}/product-delivery-assignments", [])->assertStatus(422);
    }

    public function test_contabilidad_vincula_cliente_desde_nuevo_proyecto_pero_no_entra_a_la_pantalla_clientes(): void
    {
        $this->felixComoEnScrum1139();
        $headers = $this->felixHeaders();

        // Flujo mínimo REQ-010 («Nuevo proyecto»): buscar y crear Cliente Master / Subcliente.
        // Pasan el permiso: lo que responden es la validación (falta el parámetro o el cuerpo), no un 403.
        $this->withHeaders($headers)->getJson('/api/ventas-diseno/master-clients')->assertOk();
        $this->withHeaders($headers)->getJson('/api/ventas-diseno/sub-clients')->assertStatus(422); // pasa el permiso: falta master_client_id
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/master-clients', [])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/sub-clients', [])->assertStatus(422);

        // Todo lo demás de clientes sigue cerrado: pantalla Clientes, contactos, historial ICG, categoría.
        $this->withHeaders($headers)->getJson('/api/ventas-diseno/clients')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/clients', [])->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/ventas-diseno/sub-clients/1/contacts')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/ventas-diseno/sub-clients/1/legacy-history')->assertStatus(403);
        $this->withHeaders($headers)->patchJson('/api/ventas-diseno/clients/sub-clients/1/category', [])->assertStatus(403);
    }

    public function test_sin_pipeline_edit_contabilidad_no_vincula_clientes(): void
    {
        $this->felixComoEnScrum1139();
        $this->quitarPermisosDeFelix(['ventas_diseno.pipeline.edit']);
        $headers = $this->felixHeaders();

        $this->withHeaders($headers)->getJson('/api/ventas-diseno/master-clients')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/sub-clients', [])->assertStatus(403);
    }

    public function test_contabilidad_con_pipeline_edit_tambien_replica_los_permisos_de_mark(): void
    {
        $permisos = User::where('email', self::FELIX)->firstOrFail()->effectivePermissionsV2();

        $this->assertContains('ventas_diseno.pipeline.edit', $permisos);
        $this->assertContains('ventas_diseno.edit.approved', $permisos);
        // SCRUM-1150 (decisión de Luis, 2026-10-15): Contabilidad replica a Gerencia, así que ya SÍ tiene margen, precio socio, edit y approve.
        $this->assertContains('ventas_diseno.override_min_margin', $permisos);
        $this->assertContains('ventas_diseno.select_partner_price', $permisos);
        $this->assertContains('ventas_diseno.edit', $permisos);
        $this->assertContains('ventas_diseno.approve', $permisos);
    }

    public function test_el_vendedor_dueno_conserva_su_flujo(): void
    {
        $card    = $this->makeCard('design');
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['name' => 'Propio'])->assertOk();
    }
}
