<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Tool;
use App\Modules\Servicios\Models\ToolMovement;
use App\Modules\Servicios\Models\ToolPurchaseRequest;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (Grupo D "Herramientas", REQ-268→272, SCRUM-338→342).
 * Mismos usuarios reales que TicketFlowTest/InternalTechnicianBatch6Test/ExternalTechnicianBatch5Test.
 */
class ToolsBatch13Part1Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@atlantic.com.pa';

    private const TECNICO_SERVICIOS = 'carlos@atlantic.com.pa';

    private const MANAGEMENT = 'daniela@atlantic.com.pa';

    private const VENDEDOR_DISENADOR = 'milena.e@grupolafayette.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reactivateTenant(): void
    {
        // loginAs() ya disparó una request HTTP real, que limpia el search_path que setUp() dejó
        // activo (mismo gotcha documentado en TicketBatch4Test/InternalTechnicianBatch6Test).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function carlosTechnicianId(): int
    {
        return InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->id;
    }

    private function pedroTechnicianId(): int
    {
        return InternalTechnician::where('nombre', 'Pedro Santos')->firstOrFail()->id;
    }

    /** @param array<string, mixed> $overrides */
    private function makeTool(array $overrides = []): Tool
    {
        return Tool::create([
            'nombre'       => 'Casco de seguridad',
            'codigo_unico' => 'COD-HER-' . str_pad((string) random_int(1, 999999), 3, '0', STR_PAD_LEFT) . random_int(1000, 9999),
            'estado'       => Tool::ESTADO_GOOD,
            ...$overrides,
        ]);
    }

    // ── REQ-268 — Listado agrupado por nombre (unidades sueltas, el frontend agrupa) ─────────

    public function test_index_lista_unidades_con_detalle_completo(): void
    {
        $this->makeTool(['nombre' => 'Casco de seguridad', 'codigo_unico' => 'COD-HER-001']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools')
            ->assertStatus(200);

        $tool = collect($response->json('data'))->firstWhere('codigo_unico', 'COD-HER-001');
        $this->assertNotNull($tool);
        $this->assertSame('Casco de seguridad', $tool['nombre']);
        $this->assertSame('good', $tool['estado']);
        $this->assertSame('En bodega de herramientas', $tool['assigned_to']);
        $this->assertNull($tool['responsable_incidente']);
    }

    public function test_index_sin_resultados_retorna_array_vacio(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools?search=NoExisteNiUnaSola')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_index_filtra_por_estado(): void
    {
        $this->makeTool(['codigo_unico' => 'COD-HER-100', 'estado' => Tool::ESTADO_GOOD]);
        $this->makeTool(['codigo_unico' => 'COD-HER-101', 'estado' => Tool::ESTADO_WORN]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools?estado=worn')
            ->assertStatus(200);

        $codigos = collect($response->json('data'))->pluck('codigo_unico')->all();
        $this->assertContains('COD-HER-101', $codigos);
        $this->assertNotContains('COD-HER-100', $codigos);
    }

    public function test_index_busca_por_nombre_codigo_o_persona_asignada(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $this->makeTool([
            'codigo_unico'              => 'COD-HER-200',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->toDateString(),
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $porPersona = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools?search=Vergara')
            ->assertStatus(200);
        $this->assertContains('COD-HER-200', collect($porPersona->json('data'))->pluck('codigo_unico')->all());

        $porCodigo = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools?search=COD-HER-200')
            ->assertStatus(200);
        $this->assertContains('COD-HER-200', collect($porCodigo->json('data'))->pluck('codigo_unico')->all());
    }

    public function test_index_filtra_por_assigned_to_bodega(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $this->makeTool([
            'codigo_unico'              => 'COD-HER-300',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->toDateString(),
        ]);
        $this->makeTool(['codigo_unico' => 'COD-HER-301', 'assigned_to_technician_id' => null]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools?assigned_to=bodega')
            ->assertStatus(200);

        $codigos = collect($response->json('data'))->pluck('codigo_unico')->all();
        $this->assertContains('COD-HER-301', $codigos);
        $this->assertNotContains('COD-HER-300', $codigos);
    }

    public function test_tecnico_interno_puede_ver_listado_de_herramientas(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools')
            ->assertStatus(200);
    }

    // ── REQ-269 — Cambio de estado con responsable automático ────────────────────────────────

    public function test_marcar_danada_atribuye_responsable_al_tecnico_asignado(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $tool     = $this->makeTool([
            'codigo_unico'              => 'COD-HER-400',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->toDateString(),
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);

        $this->assertSame('Carlos Vergara', $response->json('responsable_incidente'));
        $this->assertSame('damaged', $response->json('estado'));

        $this->reactivateTenant();
        $this->assertDatabaseHas((new ToolMovement())->getTable(), [
            'tool_id' => $tool->id,
            'tipo'    => ToolMovement::TIPO_DAMAGED,
        ]);
    }

    public function test_marcar_perdida_sin_asignar_usa_etiqueta_sin_asignar(): void
    {
        $tool = $this->makeTool(['codigo_unico' => 'COD-HER-401', 'assigned_to_technician_id' => null]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_LOST])
            ->assertStatus(200);

        $this->assertSame('Sin asignar al momento del incidente', $response->json('responsable_incidente'));
    }

    public function test_marcar_desgaste_no_atribuye_responsable(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $tool     = $this->makeTool([
            'codigo_unico'              => 'COD-HER-402',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->toDateString(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_WORN])
            ->assertStatus(200);

        $this->assertNull($response->json('responsable_incidente'));

        $this->reactivateTenant();
        $this->assertDatabaseHas((new ToolMovement())->getTable(), [
            'tool_id' => $tool->id,
            'tipo'    => ToolMovement::TIPO_WORN,
        ]);
    }

    public function test_volver_a_buen_estado_limpia_responsable(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $tool     = $this->makeTool([
            'codigo_unico'              => 'COD-HER-403',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->toDateString(),
            'estado'                    => Tool::ESTADO_DAMAGED,
            'responsable_incidente'     => 'Carlos Vergara',
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_GOOD])
            ->assertStatus(200);

        $this->assertNull($response->json('responsable_incidente'));

        // SCRUM-779 (2do rebote de Daniela, 2026-08-24) — restaurar desde Dañada/Perdida/Desgaste
        // a Buen estado SÍ genera un Ingreso ahora, para que el saldo del Kardex revierta la baja
        // corregida (antes quedaba restada para siempre). Ver `ToolService::updateEstado()`.
        $this->reactivateTenant();
        $this->assertDatabaseHas((new ToolMovement())->getTable(), [
            'tool_id' => $tool->id,
            'tipo'    => ToolMovement::TIPO_INGRESO,
            'detalle' => 'Restaurada a Buen estado',
        ]);
        $this->assertDatabaseCount((new ToolMovement())->getTable(), 1);
    }

    public function test_estado_invalido_retorna_422(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-404']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => 'inexistente'])
            ->assertStatus(422);
    }

    public function test_tecnico_interno_no_puede_cambiar_estado(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-405']);
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(403);
    }

    public function test_management_no_puede_cambiar_estado(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-406']);
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(403);
    }

    // ── REQ-270 — Reasignación de "Asignado a" ────────────────────────────────────────────────

    public function test_reasignar_actualiza_tecnico_y_fecha(): void
    {
        $tool     = $this->makeTool(['codigo_unico' => 'COD-HER-500', 'assigned_to_technician_id' => null]);
        $pedroId  = $this->pedroTechnicianId();

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/asignacion", ['assigned_to_technician_id' => $pedroId])
            ->assertStatus(200);

        $this->assertSame($pedroId, $response->json('assigned_to_technician_id'));
        $this->assertSame('Pedro Santos', $response->json('assigned_to'));
        $this->assertSame(now()->toDateString(), $response->json('assigned_since'));
    }

    public function test_reasignar_a_bodega_limpia_tecnico(): void
    {
        $carlosId = $this->carlosTechnicianId();
        $tool     = $this->makeTool([
            'codigo_unico'              => 'COD-HER-501',
            'assigned_to_technician_id' => $carlosId,
            'assigned_since'            => now()->subDays(10)->toDateString(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/asignacion", ['assigned_to_technician_id' => null])
            ->assertStatus(200);

        $this->assertNull($response->json('assigned_to_technician_id'));
        $this->assertSame('En bodega de herramientas', $response->json('assigned_to'));
        $this->assertSame(now()->toDateString(), $response->json('assigned_since'));
    }

    /** RN3 — reasignar NUNCA genera movimiento de kardex, a diferencia de REQ-269. */
    public function test_reasignar_no_genera_movimiento_de_kardex(): void
    {
        $tool    = $this->makeTool(['codigo_unico' => 'COD-HER-502']);
        $pedroId = $this->pedroTechnicianId();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/asignacion", ['assigned_to_technician_id' => $pedroId])
            ->assertStatus(200);

        $this->reactivateTenant();
        $this->assertDatabaseCount((new ToolMovement())->getTable(), 0);
    }

    public function test_tecnico_interno_no_puede_reasignar(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-503']);
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/asignacion", ['assigned_to_technician_id' => null])
            ->assertStatus(403);
    }

    // ── REQ-271 — Alta de herramienta nueva (solicitud de compra) ────────────────────────────

    public function test_alta_nueva_crea_solicitud_pendiente_sin_unidades_reales(): void
    {
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'Taladro inalámbrico', 'cantidad' => 3])
            ->assertStatus(201);

        $this->assertSame('solicitado', $response->json('estado'));
        $this->assertSame('Taladro inalámbrico', $response->json('nombre'));
        $this->assertSame(3, $response->json('cantidad'));

        $this->reactivateTenant();
        $this->assertDatabaseCount((new Tool())->getTable(), 0);
    }

    public function test_alta_nueva_sin_nombre_ni_cantidad_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', [])
            ->assertStatus(422);
    }

    public function test_recibir_alta_nueva_genera_unidades_con_codigos_consecutivos_en_bodega(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'Casco de seguridad', 'cantidad' => 2])
            ->assertStatus(201);
        $requestId = $create->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tools/purchase-requests/{$requestId}/recibir")
            ->assertStatus(200);

        $this->assertSame('recibida', $response->json('estado'));
        $this->assertNotNull($response->json('received_at'));

        $tools = $response->json('tools');
        $this->assertCount(2, $tools);
        foreach ($tools as $tool) {
            $this->assertSame('Casco de seguridad', $tool['nombre']);
            $this->assertSame('good', $tool['estado']);
            $this->assertSame('En bodega de herramientas', $tool['assigned_to']);
            $this->assertMatchesRegularExpression('/^COD-HER-\d+$/', $tool['codigo_unico']);
        }
        $codigos = array_column($tools, 'codigo_unico');
        $this->assertCount(2, array_unique($codigos));

        $this->reactivateTenant();
        $this->assertDatabaseCount((new Tool())->getTable(), 2);
        // RN4 — 1 movimiento "Ingreso" por unidad generada, cada uno con el detalle de todos los
        // códigos de esta recepción.
        $this->assertDatabaseCount((new ToolMovement())->getTable(), 2);
        $movements = ToolMovement::all();
        $this->assertTrue($movements->every(fn (ToolMovement $m): bool => $m->tipo === ToolMovement::TIPO_INGRESO));
        $this->assertTrue($movements->every(fn (ToolMovement $m): bool => str_contains((string) $m->detalle, $codigos[0]) && str_contains((string) $m->detalle, $codigos[1])));
    }

    public function test_recibir_solicitud_ya_recibida_retorna_409(): void
    {
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'Casco de seguridad', 'cantidad' => 1])
            ->assertStatus(201);
        $requestId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tools/purchase-requests/{$requestId}/recibir")
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tools/purchase-requests/{$requestId}/recibir")
            ->assertStatus(409);
    }

    public function test_tecnico_interno_no_puede_crear_solicitud_de_compra(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'X', 'cantidad' => 1])
            ->assertStatus(403);
    }

    public function test_vendedor_disenador_no_puede_crear_solicitud_de_compra(): void
    {
        $token = $this->loginAs(self::VENDEDOR_DISENADOR, self::VENDEDOR_DISENADOR, 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'X', 'cantidad' => 1])
            ->assertStatus(403);
    }

    // ── REQ-272 — Solicitar reposición de herramienta existente ──────────────────────────────

    public function test_solicitar_reposicion_deriva_nombre_del_tool_server_side(): void
    {
        $tool  = $this->makeTool(['nombre' => 'Escalera doble altura', 'codigo_unico' => 'COD-HER-600']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', [
                'tool_id'  => $tool->id,
                'cantidad' => 2,
                // Texto libre a propósito — RN de REQ-272: debe ignorarse, `nombre` se deriva del tool.
                'nombre'   => 'Nombre inventado por el cliente',
            ])
            ->assertStatus(201);

        $this->assertSame('Escalera doble altura', $response->json('nombre'));
        $this->assertSame($tool->id, $response->json('source_tool_id'));
    }

    public function test_reposicion_disponible_en_cualquier_unidad_no_solo_danadas(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-601', 'estado' => Tool::ESTADO_GOOD]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['tool_id' => $tool->id, 'cantidad' => 1])
            ->assertStatus(201);
    }

    public function test_recibir_reposicion_nunca_reutiliza_el_codigo_original(): void
    {
        $original = $this->makeTool(['nombre' => 'Guantes de seguridad', 'codigo_unico' => 'COD-HER-602']);
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['tool_id' => $original->id, 'cantidad' => 1])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tools/purchase-requests/{$create->json('id')}/recibir")
            ->assertStatus(200);

        $nuevoCodigo = $response->json('tools.0.codigo_unico');
        $this->assertNotSame('COD-HER-602', $nuevoCodigo);

        // La unidad original queda intacta — la reposición nunca la toca.
        $this->reactivateTenant();
        $original->refresh();
        $this->assertSame('COD-HER-602', $original->codigo_unico);
        $this->assertSame('Guantes de seguridad', $original->nombre);
    }

    public function test_index_muestra_solicitud_de_reposicion_pendiente_en_la_unidad_original(): void
    {
        $tool  = $this->makeTool(['codigo_unico' => 'COD-HER-603']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tools/purchase-requests', ['tool_id' => $tool->id, 'cantidad' => 4])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('codigo_unico', 'COD-HER-603');
        $this->assertNotNull($row['pending_replacement_request']);
        $this->assertSame(4, $row['pending_replacement_request']['cantidad']);
    }

    // ── Listado de solicitudes (companion de REQ-271 RN2 / REQ-272) ──────────────────────────

    public function test_listado_de_solicitudes_visible_para_lectura_del_modulo(): void
    {
        $tokenLider = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($tokenLider))
            ->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'Andamio', 'cantidad' => 1])
            ->assertStatus(201);

        $tokenTecnico = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $response = $this->withHeaders($this->authHeaders($tokenTecnico))
            ->getJson('/api/servicios/tools/purchase-requests')
            ->assertStatus(200);

        $this->assertContains('Andamio', collect($response->json('data'))->pluck('nombre')->all());
    }
}
