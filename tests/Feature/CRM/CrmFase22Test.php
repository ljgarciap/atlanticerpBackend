<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Services\CrmStages;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 22 (docs/specs/fase22-crm-consistente.md) — B3 motivo y reactivación, B4 contactos con dueño, B5 etapas como catálogo.
 */
final class CrmFase22Test extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PrimaryApprovers::forget();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function userId(string $email): int
    {
        return (int) DB::table('atlantic_auth.users')->where('email', $email)->value('id');
    }

    private function vendedor(): array
    {
        return $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
    }

    private function gerencia(): array
    {
        return $this->authHeaders($this->loginAs());
    }

    private function proyectoDelVendedor(): int
    {
        $this->tenant();
        $project = Project::create(['nombre' => 'Proyecto F22', 'tipo' => 'diseno', 'etapa' => 'propuesta']);
        DB::table('atlantic_crm.project_assignees')->insert(['project_id' => $project->id, 'user_id' => $this->userId('vendedor@atlantic.test')]);

        return $project->id;
    }

    // ── B3 ────────────────────────────────────────────────────────────────────

    public function test_perder_un_proyecto_exige_motivo_y_lo_deja_guardado(): void
    {
        $id = $this->proyectoDelVendedor();
        $headers = $this->vendedor();

        $this->withHeaders($headers)->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'perdido'])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->withHeaders($headers)->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'perdido', 'motivo' => 'corto'])->assertStatus(422);
        $this->withHeaders($headers)->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'perdido', 'motivo' => 'El cliente eligió a otro proveedor'])
            ->assertOk()->assertJsonPath('etapa', 'perdido')->assertJsonPath('lost_reason', 'El cliente eligió a otro proveedor');

        $this->tenant();
        $fresh = Project::findOrFail($id);
        $this->assertSame($this->userId('vendedor@atlantic.test'), $fresh->lost_by);
        $this->assertNotNull($fresh->lost_at);
    }

    public function test_los_demas_saltos_de_etapa_siguen_libres(): void
    {
        $id = $this->proyectoDelVendedor();

        $this->withHeaders($this->vendedor())->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'cerrado'])->assertOk()->assertJsonPath('etapa', 'cerrado');
    }

    public function test_reactivar_un_perdido_solo_lo_decide_el_aprobador_o_un_suplente(): void
    {
        $id = $this->proyectoDelVendedor();
        $this->tenant();
        Project::whereKey($id)->update(['etapa' => 'perdido']);
        ComprasSettings::current()->update(['primary_approver_user_id' => null]);
        $url = "/api/crm/projects/{$id}/stage";

        // El vendedor es asignado pero no es el aprobador: 403 aunque mande el motivo.
        $this->withHeaders($this->vendedor())->patchJson($url, ['etapa' => 'lead', 'motivo' => 'Quiero retomarlo, por favor'])->assertStatus(403);
        $this->tenant();
        $this->assertSame('perdido', Project::findOrFail($id)->etapa);
        $this->withHeaders($this->vendedor())->getJson("/api/crm/projects/{$id}")->assertOk()->assertJsonPath('can_reactivate', false);

        // Designado suplente: puede, y el motivo es obligatorio.
        $vendedorId = $this->userId('vendedor@atlantic.test');
        DB::table('atlantic_auth.primary_approver_substitutes')->insert(['user_id' => $vendedorId, 'created_at' => now(), 'updated_at' => now()]);
        PrimaryApprovers::forget();
        $headers = $this->vendedor();
        $this->tenant();
        $this->withHeaders($headers)->getJson("/api/crm/projects/{$id}")->assertOk()->assertJsonPath('can_reactivate', true);
        $this->withHeaders($headers)->patchJson($url, ['etapa' => 'lead'])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->withHeaders($headers)->patchJson($url, ['etapa' => 'lead', 'motivo' => 'Quiero retomarlo, por favor'])
            ->assertOk()->assertJsonPath('etapa', 'lead')->assertJsonPath('reopened_reason', 'Quiero retomarlo, por favor');

        $this->tenant();
        $this->assertSame($vendedorId, Project::findOrFail($id)->reopened_by);
    }

    public function test_no_se_puede_crear_un_proyecto_directamente_como_perdido(): void
    {
        $this->withHeaders($this->vendedor())->postJson('/api/crm/projects', ['nombre' => 'Nace perdido', 'tipo' => 'diseno', 'etapa' => 'perdido'])->assertStatus(422)->assertJsonValidationErrors('etapa');
    }

    // ── B4 ────────────────────────────────────────────────────────────────────

    public function test_un_contacto_guarda_quien_lo_creo_y_solo_el_o_gerencia_lo_edita_y_borra(): void
    {
        $vendedor = $this->vendedor();
        $creado = $this->withHeaders($vendedor)->postJson('/api/crm/contacts', ['nombre' => 'Contacto F22', 'tipo' => 'developer'])->assertStatus(201);
        $id = (int) $creado->json('id');
        $this->tenant();
        $this->assertSame($this->userId('vendedor@atlantic.test'), Contact::findOrFail($id)->created_by);

        // Otra persona del CRM sin alcance de equipo no lo toca.
        $this->tenant();
        $ajeno = Contact::create(['nombre' => 'Ajeno F22', 'tipo' => 'developer', 'created_by' => $this->userId('gerencia@test.com')]);
        $this->withHeaders($vendedor)->putJson("/api/crm/contacts/{$ajeno->id}", ['nombre' => 'Cambiado'])->assertStatus(403);
        $this->withHeaders($vendedor)->deleteJson("/api/crm/contacts/{$ajeno->id}")->assertStatus(403);

        // Su creador sí; Gerencia (ve al equipo) también.
        $this->withHeaders($vendedor)->putJson("/api/crm/contacts/{$id}", ['empresa' => 'ACME'])->assertOk();
        $this->withHeaders($this->gerencia())->putJson("/api/crm/contacts/{$id}", ['empresa' => 'ACME 2'])->assertOk();
        $this->tenant();
        $this->assertSame(Contact::findOrFail($id)->updated_by, $this->userId('management@atlantic.test'));
        $this->withHeaders($this->gerencia())->deleteJson("/api/crm/contacts/{$ajeno->id}")->assertStatus(204);
    }

    public function test_un_contacto_sin_dueno_lo_gestiona_cualquiera_del_crm(): void
    {
        $this->tenant();
        $huerfano = Contact::create(['nombre' => 'Huérfano F22', 'tipo' => 'developer']);

        $this->withHeaders($this->vendedor())->putJson("/api/crm/contacts/{$huerfano->id}", ['empresa' => 'Nueva'])->assertOk();
        $this->withHeaders($this->vendedor())->deleteJson("/api/crm/contacts/{$huerfano->id}")->assertStatus(204);
    }

    public function test_la_lista_de_contactos_muestra_quien_lo_creo(): void
    {
        $this->withHeaders($this->vendedor())->postJson('/api/crm/contacts', ['nombre' => 'Visible F22', 'tipo' => 'developer'])->assertStatus(201);
        $fila = collect($this->withHeaders($this->gerencia())->getJson('/api/crm/contacts?q=Visible+F22')->assertOk()->json('entries'))->firstWhere('nombre', 'Visible F22');

        $this->assertNotNull($fila['created_by']);
        $this->assertNotEmpty($fila['created_by_name']);
    }

    // ── B5 ────────────────────────────────────────────────────────────────────

    public function test_la_siembra_del_catalogo_reproduce_las_etapas_de_siempre(): void
    {
        $this->tenant();
        $this->assertSame(['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'], CrmStages::keys());
        $this->assertSame(['lead', 'diseno', 'cotizacion', 'propuesta'], CrmStages::open());
        $this->assertSame(['cerrado'], CrmStages::won());
        $this->assertSame(['perdido'], CrmStages::lost());
    }

    public function test_una_etapa_propia_es_abierta_se_acepta_y_cuenta_en_el_tablero(): void
    {
        $this->tenant();
        (new CatalogService)->create(CrmStages::DOMAIN, ['key' => 'negociacion', 'label' => 'Negociación']);
        $this->assertContains('negociacion', CrmStages::open(), 'una etapa propia siempre es abierta');

        $id = $this->proyectoDelVendedor();
        $this->withHeaders($this->vendedor())->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'negociacion'])->assertOk()->assertJsonPath('etapa', 'negociacion');

        $kpis = $this->withHeaders($this->vendedor())->getJson('/api/crm/dashboard/kpis')->assertOk();
        $this->assertSame(1, $kpis->json('counts_by_stage.negociacion'));
        $this->withHeaders($this->vendedor())->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'no_existe'])->assertStatus(422);
    }
}
