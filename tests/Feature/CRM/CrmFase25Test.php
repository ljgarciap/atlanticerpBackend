<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\CRM\Models\ShareLink;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 25 (docs/specs/fase25-crm-listas-y-enlaces.md) — listas del CRM como catálogo, forma `{data, meta}` de contactos y seguimiento de enlaces.
 */
final class CrmFase25Test extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    private function proyecto(): int
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        return Project::create(['nombre' => 'Proyecto F25', 'tipo' => 'diseno', 'etapa' => 'lead'])->id;
    }

    // ── Listas como catálogo ──────────────────────────────────────────────────

    public function test_los_valores_historicos_estan_sembrados(): void
    {
        $h = $this->superadmin();
        foreach ([
            'crm_project_type' => ['diseno', 'cotizacion', 'ambos'],
            'crm_activity_type' => ['llamada', 'email', 'reunion', 'whatsapp', 'visita', 'otro'],
            'crm_reminder_type' => ['llamada', 'cotizacion', 'diseno', 'otro'],
            'crm_document_category' => ['cotizacion', 'plano', 'modelo_3d', 'otro'],
        ] as $dominio => $claves) {
            $keys = collect($this->withHeaders($h)->getJson("/api/catalogs/{$dominio}")->assertOk()->json() ?? [])->pluck('key')->all();
            foreach ($claves as $clave) {
                $this->assertContains($clave, $keys, "{$dominio} debe traer {$clave}");
            }
        }
    }

    public function test_un_tipo_de_proyecto_propio_se_acepta_y_uno_ajeno_no(): void
    {
        $h = $this->superadmin();
        $nuevo = ['nombre' => 'Proyecto tipo propio', 'tipo' => 'mantenimiento', 'etapa' => 'lead'];

        $this->withHeaders($h)->postJson('/api/crm/projects', $nuevo)->assertStatus(422)->assertJsonValidationErrors('tipo');

        $this->withHeaders($h)->postJson('/api/admin/catalogs/crm_project_type', ['key' => 'mantenimiento', 'label' => 'Mantenimiento'])->assertCreated();
        $id = (int) $this->withHeaders($h)->postJson('/api/crm/projects', $nuevo)->assertCreated()->json('id');
        $this->withHeaders($h)->putJson("/api/crm/projects/{$id}", ['tipo' => 'ambos'])->assertOk();
    }

    public function test_un_tipo_de_actividad_y_de_recordatorio_propios_se_aceptan(): void
    {
        $h = $this->superadmin();
        $id = $this->proyecto();
        $actividad = ['tipo' => 'capacitacion', 'fecha' => now()->toDateString(), 'descripcion' => 'Sesión con el cliente'];
        $recordatorio = ['tipo' => 'capacitacion', 'fecha' => now()->addDay()->toDateString(), 'nota' => 'Preparar'];

        $this->withHeaders($h)->postJson("/api/crm/projects/{$id}/activities", $actividad)->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->withHeaders($h)->postJson("/api/crm/projects/{$id}/reminders", $recordatorio)->assertStatus(422)->assertJsonValidationErrors('tipo');

        $this->withHeaders($h)->postJson('/api/admin/catalogs/crm_activity_type', ['key' => 'capacitacion', 'label' => 'Capacitación'])->assertCreated();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/crm_reminder_type', ['key' => 'capacitacion', 'label' => 'Capacitación'])->assertCreated();

        $this->withHeaders($h)->postJson("/api/crm/projects/{$id}/activities", $actividad)->assertCreated();
        $this->withHeaders($h)->postJson("/api/crm/projects/{$id}/reminders", $recordatorio)->assertCreated();
    }

    public function test_el_tablero_y_el_reporte_cuentan_los_tipos_del_catalogo(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/crm_project_type', ['key' => 'mantenimiento', 'label' => 'Mantenimiento'])->assertCreated();
        $this->withHeaders($h)->postJson('/api/crm/projects', ['nombre' => 'Mant', 'tipo' => 'mantenimiento', 'etapa' => 'lead'])->assertCreated();

        $conteo = $this->withHeaders($h)->getJson('/api/crm/dashboard/kpis')->assertOk()->json('counts_by_tipo');
        $this->assertSame(1, $conteo['mantenimiento'] ?? null);
        $this->assertArrayHasKey('ambos', $conteo, 'un tipo activo sin proyectos sale en cero');
    }

    public function test_una_categoria_de_documento_en_uso_no_se_borra(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/crm_document_category', ['key' => 'contrato', 'label' => 'Contrato'])->assertCreated();
        $id = (int) DB::table('atlantic_auth.catalog_entries')->where('domain', 'crm_document_category')->where('key', 'contrato')->value('id');

        $proyecto = $this->proyecto();
        ProjectDocument::create([
            'project_id' => $proyecto, 'categoria' => 'contrato', 'nombre_archivo' => 'c.pdf', 'storage_key' => 'crm/x/c.pdf', 'size_bytes' => 10,
            'mime_type' => 'application/pdf', 'storage_zone' => ProjectDocument::ZONE_PRIVATE, 'version' => 1, 'is_current' => true,
            'uploaded_by' => (int) DB::table('atlantic_auth.users')->value('id'),
        ]);

        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/crm_document_category/{$id}")->assertStatus(422);
    }

    // ── Forma {data, meta} de contactos ───────────────────────────────────────

    public function test_la_lista_de_contactos_responde_data_y_meta(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/crm/contacts', ['nombre' => 'Contacto F25', 'tipo' => 'contacto'])->assertCreated();

        $r = $this->withHeaders($h)->getJson('/api/crm/contacts?q=Contacto+F25')->assertOk();
        $r->assertJsonStructure(['data', 'meta' => ['total', 'total_projects', 'total_valor', 'total_cerrado']]);
        $this->assertSame(1, $r->json('meta.total'));
        $this->assertArrayNotHasKey('entries', $r->json());
        $this->assertArrayNotHasKey('stats', $r->json());
    }

    // ── Seguimiento de enlaces ────────────────────────────────────────────────

    public function test_el_enlace_guarda_a_quien_se_compartio_y_el_listado_cuenta_las_aperturas(): void
    {
        $h = $this->superadmin();
        $proyecto = $this->proyecto();
        $doc = ProjectDocument::create([
            'project_id' => $proyecto, 'categoria' => 'otro', 'nombre_archivo' => 'p.pdf', 'storage_key' => 'crm/x/p.pdf', 'size_bytes' => 10,
            'mime_type' => 'application/pdf', 'storage_zone' => ProjectDocument::ZONE_PUBLIC, 'public_url' => 'https://cdn.example.test/p.pdf',
            'version' => 1, 'is_current' => true, 'uploaded_by' => (int) DB::table('atlantic_auth.users')->value('id'),
        ]);
        $base = "/api/crm/projects/{$proyecto}/documents/{$doc->id}/share-links";

        $this->withHeaders($h)->postJson($base, ['destinatario' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors('destinatario');

        $con = $this->withHeaders($h)->postJson($base, ['destinatario' => '  Cliente de prueba  '])->assertCreated()->assertJsonPath('destinatario', 'Cliente de prueba');
        $sin = $this->withHeaders($h)->postJson($base)->assertCreated()->assertJsonPath('destinatario', null);

        $token = basename((string) $con->json('url'));
        $this->get("/public/share/{$token}")->assertRedirect();
        $this->get("/public/share/{$token}")->assertRedirect();

        $lista = collect($this->withHeaders($h)->getJson($base)->assertOk()->json());
        $this->assertSame(2, $lista->firstWhere('id', $con->json('id'))['access_count']);
        $this->assertNotNull($lista->firstWhere('id', $con->json('id'))['last_accessed_at']);
        $this->assertSame(0, $lista->firstWhere('id', $sin->json('id'))['access_count']);
        $this->assertSame('Cliente de prueba', $lista->firstWhere('id', $con->json('id'))['destinatario']);
    }
}
