<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Exceptions\ExternalTechnicianDeactivationRequiresConfirmationException;
use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Services\ExternalTechnicianService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-263→267) — Técnicos externos. Mismos usuarios reales que
 * TicketBatch4Test.
 */
class ExternalTechnicianBatch5Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Escenario REQ-264 — alta con campos obligatorios/opcionales, valores default. */
    public function test_registrar_tecnico_externo(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Tecnico Servicios Gómez',
                'empresa' => 'Gómez Instalaciones',
                'tarifa_dia' => 28,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('estado', 'active');
        $response->assertJsonPath('tarifa_visible', true);
        $response->assertJsonPath('especialidad', 'Sin especificar');
        $response->assertJsonPath('tarifa_dia', 28);

        $id = $response->json('id');
        $ficha = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertStatus(200);

        $this->assertCount(1, $ficha->json('rate_history'));
        $ficha->assertJsonPath('rate_history.0.tarifa_dia', '28.00');
    }

    public function test_registrar_sin_nombre_ni_empresa_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', ['tarifa_dia' => 20])
            ->assertStatus(422);
    }

    /** RN4 (REQ-264) — solo Lider Servicios/Líder de Servicios y Gerencia registran técnicos. */
    public function test_tecnico_interno_no_puede_registrar(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])
            ->assertStatus(403);
    }

    /** RN5 (REQ-263) — técnico interno tiene acceso de solo consulta al listado. */
    public function test_tecnico_interno_puede_listar(): void
    {
        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Luis Vargas', 'empresa' => 'Vargas SA', 'tarifa_dia' => 30,
            ])->assertStatus(201);

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson('/api/servicios/external-technicians')
            ->assertStatus(200);
    }

    /**
     * Hallazgo Pre-QA 2026-08-09 (fix en routes/servicios.php) — RN5 (REQ-263) solo otorga
     * solo-consulta a Lider Servicios/técnicos internos/Gerencia; vendedor tiene servicios.read
     * por otras razones del módulo (crear/ver tickets) pero la matriz de permisos del Excel marca
     * N/A para Vendedor/Diseñador en las 5 filas de Técnicos Externos — antes del fix, el permiso
     * de módulo compartido le daba acceso real de lectura (incl. tarifa) pese a que el frontend ya
     * ocultaba la opción de navegación (ServiciosNavMenu.tsx, LIMITED_ROLE).
     */
    public function test_vendedor_disenador_no_puede_ver_tecnicos_externos(): void
    {
        $vendedorToken = $this->loginAs('vendedordisenador@test.com', 'vendedordisenador@test.com', 'vendedor');

        $this->withHeaders($this->authHeaders($vendedorToken))
            ->getJson('/api/servicios/external-technicians')
            ->assertStatus(403);
    }

    /** RN5 (REQ-263) — tecnico_garantias (también técnico interno) conserva el acceso de solo consulta. */
    public function test_garantias_servicios_puede_listar(): void
    {
        $garantiasToken = $this->loginAs('garantiasservicios@test.com', 'garantiasservicios@test.com', 'tecnico_garantias');

        $this->withHeaders($this->authHeaders($garantiasToken))
            ->getJson('/api/servicios/external-technicians')
            ->assertStatus(200);
    }

    /** Escenario 1 (REQ-263) — chip Activos filtra correctamente. */
    public function test_filtro_por_estado(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $activeId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Activo Uno', 'empresa' => 'A', 'tarifa_dia' => 20,
            ])->assertStatus(201)->json('id');

        $inactiveId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Inactivo Uno', 'empresa' => 'B', 'tarifa_dia' => 20,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$inactiveId}/estado", ['estado' => 'inactive'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/external-technicians?estado=active')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_buscador_filtra_por_nombre(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Roberto Alvarado', 'empresa' => 'RA Instalaciones', 'tarifa_dia' => 25,
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Marta Solís', 'empresa' => 'MS', 'tarifa_dia' => 25,
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/external-technicians?search=roberto')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('nombre')->all();
        $this->assertSame(['Roberto Alvarado'], $names);
    }

    /** Escenario 1 (REQ-266) — el historial se acumula al editar la tarifa. */
    public function test_editar_tarifa_acumula_historial(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Con Historial', 'empresa' => 'X', 'tarifa_dia' => 25,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$id}/tarifa", ['tarifa_dia' => 28])
            ->assertStatus(200)
            ->assertJsonPath('tarifa_dia', '28.00');

        $ficha = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertStatus(200);

        $this->assertCount(2, $ficha->json('rate_history'));
        $tarifas = collect($ficha->json('rate_history'))->pluck('tarifa_dia')->all();
        $this->assertEqualsCanonicalizing(['25.00', '28.00'], $tarifas);
    }

    /** Escenario 2 (REQ-266) — ocultar tarifa no crea entrada de historial ni afecta el valor real. */
    public function test_ocultar_tarifa_no_genera_historial_ni_afecta_valor_real(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Tarifa Oculta', 'empresa' => 'X', 'tarifa_dia' => 25,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$id}/tarifa", ['tarifa_visible' => false])
            ->assertStatus(200)
            ->assertJsonPath('tarifa_visible', false)
            ->assertJsonPath('tarifa_dia', null);

        $ficha = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertStatus(200);
        $ficha->assertJsonPath('tarifa_dia', null);
        $this->assertCount(1, $ficha->json('rate_history'));

        // El valor real sigue ahí — la ocultación es puramente visual (RN3). Re-activar el
        // tenant explícitamente: la request HTTP anterior ya limpió el search_path (mismo
        // gotcha documentado en TicketBatch4Test).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $technician = ExternalTechnician::find($id);
        $this->assertNotNull($technician);
        $this->assertSame('25.00', $technician->tarifa_dia);
    }

    /** RN1 (REQ-266) — solo Lider Servicios/Gerencia editan tarifa. */
    public function test_tecnico_interno_no_puede_editar_tarifa(): void
    {
        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->patchJson("/api/servicios/external-technicians/{$id}/tarifa", ['tarifa_dia' => 99])
            ->assertStatus(403);
    }

    /** Escenario 2 (REQ-265) — sin proyectos activos, inactivar aplica directo sin advertencia. */
    public function test_inactivar_sin_proyectos_activos_aplica_directo(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$id}/estado", ['estado' => 'inactive'])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'inactive');
    }

    /** Escenario 3 (REQ-265) — reactivar nunca exige confirmación. */
    public function test_reactivar_no_exige_confirmacion(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$id}/estado", ['estado' => 'inactive'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$id}/estado", ['estado' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'active');
    }

    /** RN1 (REQ-265) — solo Lider Servicios/Gerencia activan/inactivan. */
    public function test_tecnico_interno_no_puede_inactivar(): void
    {
        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->patchJson("/api/servicios/external-technicians/{$id}/estado", ['estado' => 'inactive'])
            ->assertStatus(403);
    }

    /** REQ-267 RN2 — costo/margen (acá, la bandera que los habilita) solo para Lider Servicios/Gerencia. */
    public function test_puede_ver_costos_gateado_por_rol(): void
    {
        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($liderToken))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertJsonPath('puede_ver_costos', true);

        $managementToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');
        $this->withHeaders($this->authHeaders($managementToken))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertJsonPath('puede_ver_costos', true);

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertJsonPath('puede_ver_costos', false);
    }

    /**
     * SCRUM-337 (retest QA 2026-08-19, 2do CRÍTICO) — el fix del rebote anterior gateó tarifa_dia
     * en show() (la ficha) pero nunca en summary() (usado por index()) — la tabla de listado le
     * seguía mostrando la tarifa real a un técnico interno con el toggle "Mostrar tarifa" activo.
     */
    public function test_listado_no_expone_tarifa_a_quien_no_puede_verla(): void
    {
        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($liderToken))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'Con Tarifa Visible', 'empresa' => 'X', 'tarifa_dia' => 25,
            ])->assertStatus(201);

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $response = $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson('/api/servicios/external-technicians')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('nombre', 'Con Tarifa Visible');
        $this->assertNull($row['tarifa_dia']);

        $liderRow = collect(
            $this->withHeaders($this->authHeaders($liderToken))
                ->getJson('/api/servicios/external-technicians')
                ->json('data'),
        )->firstWhere('nombre', 'Con Tarifa Visible');
        $this->assertSame('25.00', $liderRow['tarifa_dia']);
    }

    /** RN3 (REQ-267) — sin proyectos asignados, el array viene vacío (el mensaje lo resuelve el frontend). */
    public function test_ficha_sin_proyectos_asignados_trae_array_vacio(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$id}")
            ->assertJsonPath('proyectos_asignados', [])
            ->assertJsonPath('proyectos_activos', 0);
    }

    public function test_ficha_inexistente_retorna_404(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/external-technicians/999999')
            ->assertStatus(404);
    }

    /**
     * REQ-267 RN1 / REQ-265 RN2 — contrato del gate de advertencia al inactivar.
     */
    public function test_excepcion_de_confirmacion_expone_el_conteo_y_mensaje_esperado(): void
    {
        $exception = new ExternalTechnicianDeactivationRequiresConfirmationException(2);

        $this->assertSame(2, $exception->activeProjectsCount());
        $this->assertSame(
            'Este técnico tiene 2 proyecto(s) activo(s) — ¿de todas formas quieres inactivarlo?',
            $exception->getMessage(),
        );
    }

    /**
     * REQ-267 — desde Batch 11 (SCRUM-292→297), `activeProjectsCount()` consulta datos reales
     * (`ServiceQuoteItem` tipo=subcontracted, ver `ExternalTechnicianService`). Sin ninguna línea
     * Subcontratado asignada a este técnico, sigue en 0 — mismo resultado que el stub anterior a
     * Batch 11, pero ahora por una query real, no por un valor fijo. El escenario con asignaciones
     * reales vive en `Tests\Feature\Servicios\ServiceQuoteBatch11Test::test_sincroniza_proyectos_activos_del_tecnico_externo()`.
     */
    public function test_active_projects_count_es_cero_sin_asignaciones_reales(): void
    {
        $service = app(ExternalTechnicianService::class);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/external-technicians', [
                'nombre' => 'X', 'empresa' => 'Y', 'tarifa_dia' => 10,
            ])->assertStatus(201)->json('id');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $technician = ExternalTechnician::find($id);
        $this->assertNotNull($technician);
        $this->assertSame(0, $service->activeProjectsCount($technician));
    }
}
