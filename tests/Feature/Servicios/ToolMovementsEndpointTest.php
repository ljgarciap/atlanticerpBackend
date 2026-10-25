<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Tool;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-276, SCRUM-346 "Kardex de herramientas") — el ticket es
 * 100% frontend salvo `GET /api/servicios/tools/movements`, ver docblock de
 * `ToolService::listMovements()`.
 */
class ToolMovementsEndpointTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTool(array $overrides = []): Tool
    {
        return Tool::create([
            'nombre' => 'Escalera',
            'codigo_unico' => 'COD-HER-'.random_int(100000, 999999),
            'estado' => Tool::ESTADO_GOOD,
            ...$overrides,
        ]);
    }

    public function test_lista_movimientos_de_todas_las_herramientas(): void
    {
        $toolA = $this->makeTool(['nombre' => 'Escalera A']);
        $toolB = $this->makeTool(['nombre' => 'Escalera B']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$toolA->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$toolB->id}/estado", ['estado' => Tool::ESTADO_WORN])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools/movements')
            ->assertStatus(200);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
        $tipos = collect($response->json('data'))->pluck('tipo')->all();
        $this->assertContains('damaged', $tipos);
        $this->assertContains('worn', $tipos);
    }

    /**
     * SCRUM-779 (rebote Gerencia 2026-08-20) — antes el PATCH ni siquiera aceptaba `detalle`, así
     * que el Kardex siempre mostraba "—" para Dañada/Perdida/Desgaste. Ahora se captura y viaja
     * intacto. `cantidad` también nuevo — siempre 1 (cada fila es una unidad física puntual).
     *
     * SCRUM-779 (2do rebote de Gerencia, 2026-08-24) — el motivo de texto libre ya no es el
     * `detalle` completo: REQ-276 punto 3 pide literal "Dañada por [Nombre]" primero, ver
     * `ToolService::incidentDetalle()`. El motivo capturado por el modal se conserva agregado.
     */
    public function test_detalle_se_captura_para_danada_perdida_y_desgaste_y_cantidad_viaja_en_1(): void
    {
        $tool = $this->makeTool();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", [
                'estado' => Tool::ESTADO_DAMAGED,
                'detalle' => 'Cable pelado detectado en inspección de rutina',
            ])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tools/movements?tool_id={$tool->id}")
            ->assertStatus(200);

        $row = $response->json('data.0');
        $this->assertSame(
            'Dañada por Sin asignar al momento del incidente — Cable pelado detectado en inspección de rutina',
            $row['detalle'],
        );
        $this->assertSame(1, $row['cantidad']);
    }

    /**
     * SCRUM-779 (decisión de Luis 2026-08-23) — saldo = unidades en Buen estado agrupadas por
     * `nombre`, Ingreso suma / Dañada-Perdida-Desgaste resta, calculado sobre el historial
     * COMPLETO en orden cronológico — nunca sobre el subconjunto que un filtro deja visible.
     */
    public function test_saldo_se_calcula_por_nombre_en_orden_cronologico_y_no_se_altera_al_filtrar(): void
    {
        $toolA = $this->makeTool(['nombre' => 'Escalera']);
        $toolB = $this->makeTool(['nombre' => 'Escalera']);
        $otra = $this->makeTool(['nombre' => 'Taladro']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $headers = $this->authHeaders($token);

        // Movimiento 1 — Escalera A a Dañada: saldo 0 -> -1.
        $this->withHeaders($headers)
            ->patchJson("/api/servicios/tools/{$toolA->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);

        // Movimiento sobre OTRO nombre no debe interferir con el saldo de "Escalera".
        $this->withHeaders($headers)
            ->patchJson("/api/servicios/tools/{$otra->id}/estado", ['estado' => Tool::ESTADO_LOST])
            ->assertStatus(200);

        // Movimiento 2 — Escalera B a Desgaste: saldo -1 -> -2.
        $this->withHeaders($headers)
            ->patchJson("/api/servicios/tools/{$toolB->id}/estado", ['estado' => Tool::ESTADO_WORN])
            ->assertStatus(200);

        $full = $this->withHeaders($headers)
            ->getJson("/api/servicios/tools/movements?tool_id={$toolB->id}")
            ->assertStatus(200)->json('data.0');
        $this->assertSame(-1, $full['saldo_inicial']);
        $this->assertSame(-2, $full['saldo_resultante']);

        // Filtrando por tipo=worn, la única fila visible sigue mostrando el saldo real de su
        // posición en el historial completo (-1 -> -2), NUNCA recalculado como si fuera el primer
        // movimiento (0 -> -1) solo porque es la única fila que el filtro deja ver.
        $filteredByTipo = $this->withHeaders($headers)
            ->getJson('/api/servicios/tools/movements?tipo=worn')
            ->assertStatus(200)->json('data.0');
        $this->assertSame(-1, $filteredByTipo['saldo_inicial']);
        $this->assertSame(-2, $filteredByTipo['saldo_resultante']);
    }

    public function test_filtra_por_tool_id(): void
    {
        $toolA = $this->makeTool(['nombre' => 'Escalera A']);
        $toolB = $this->makeTool(['nombre' => 'Escalera B']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$toolA->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$toolB->id}/estado", ['estado' => Tool::ESTADO_WORN])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tools/movements?tool_id={$toolA->id}")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertSame($toolA->id, $row['tool_id']);
        }
    }

    public function test_filtra_por_tipo(): void
    {
        $tool = $this->makeTool();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools/movements?tipo=worn')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_filtra_por_realizado_por(): void
    {
        $tool = $this->makeTool();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tools/{$tool->id}/estado", ['estado' => Tool::ESTADO_DAMAGED])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools/movements?user_id=999999')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    // Senior Review 2026-08-13 — CORREGIDO: REQ-276 restringe este kardex a "Lider Servicios y Gerencia",
    // no a todo el módulo (a diferencia de `/tools` y `/tools/purchase-requests`). El test
    // original afirmaba lo contrario (200 para técnico) — reflejaba el gate real ANTES del fix,
    // no lo que pedía el ticket.
    public function test_tecnico_interno_n_o_puede_leer_el_kardex_de_herramientas(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools/movements')
            ->assertStatus(403);
    }

    public function test_lider_servicios_si_puede_leer_el_kardex(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tools/movements')
            ->assertStatus(200);
    }
}
