<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/** SCRUM-329 Oleada A / Batch A2 (REQ-307 RN3) — CRUD del mapeo vendedor→Asistente de Bodega. */
class WarehouseAssistantMappingControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    public function test_jefe_de_bodega_puede_crear_el_mapeo(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $assistantId = $this->userIdByEmail('asistentebodega2@test.com'); // Asistente Bodega
        $this->grantBodegaPermissions('liderbodega@test.com'); // Lider Bodega
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $sellerId, 'assistant_id' => $assistantId])
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $this->assertSame(1, WarehouseAssistantMapping::where('seller_user_id', $sellerId)->where('is_active', true)->count());
    }

    /** Un Asistente de Bodega (no el Jefe) NO puede editar el mapeo. */
    public function test_asistente_de_bodega_no_puede_crear_el_mapeo(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $assistantId = $this->userIdByEmail('asistentebodega2@test.com');
        $this->grantBodegaPermissions('asistentebodega@test.com'); // Asistente Bodega, asistente_bodega
        $token = $this->loginAs('asistentebodega@test.com', 'asistentebodega@test.com', 'asistente_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $sellerId, 'assistant_id' => $assistantId])
            ->assertStatus(403);
    }

    /** Reasignar un vendedor desactiva el mapeo anterior — nunca 2 filas activas para el mismo vendedor. */
    public function test_reasignar_vendedor_desactiva_el_mapeo_anterior(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $assistant1 = $this->userIdByEmail('asistentebodega2@test.com');
        $assistant2 = $this->userIdByEmail('asistentebodega@test.com');
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $sellerId, 'assistant_id' => $assistant1])->assertStatus(201);
        $this->withHeaders($headers)->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $sellerId, 'assistant_id' => $assistant2])->assertStatus(201);

        $this->makeCurrentTenant();
        $this->assertSame(1, WarehouseAssistantMapping::where('seller_user_id', $sellerId)->where('is_active', true)->count());
        $this->assertSame($assistant2, WarehouseAssistantMapping::where('seller_user_id', $sellerId)->where('is_active', true)->value('assistant_user_id'));
    }

    public function test_index_lista_solo_mapeos_activos(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $assistantId = $this->userIdByEmail('asistentebodega2@test.com');
        // WarehouseAssistantMappingSeeder (Batch A1) ya siembra un mapeo activo real para Vendedor
        // (REQ-307 RN1) — se limpia acá para que este test controle su propio estado, sin
        // acoplarse al roster real de vendedores/asistentes del seeder.
        WarehouseAssistantMapping::where('seller_user_id', $sellerId)->delete();
        WarehouseAssistantMapping::create(['seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true]);

        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/warehouse-assistant-mapping')
            ->assertStatus(200)
            ->assertJsonFragment(['seller_id' => $sellerId, 'assistant_id' => $assistantId]);
    }

    /** Fase 29 — el mapeo solo acepta un vendedor de ventas y un asistente de Bodega. */
    public function test_mapeo_rechaza_usuarios_sin_el_rol_que_corresponde(): void
    {
        $this->grantBodegaPermissions('liderbodega@test.com');
        $headers = $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
        $vendedor = $this->userIdByEmail('vendedor6@test.com');
        $asistente = $this->userIdByEmail('asistentebodega2@test.com');
        $gerencia = $this->userIdByEmail('gerencia@test.com');

        $this->withHeaders($headers)->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $gerencia, 'assistant_id' => $asistente])
            ->assertStatus(422)->assertJsonValidationErrors('seller_id');
        $this->withHeaders($headers)->postJson('/api/bodega/warehouse-assistant-mapping', ['seller_id' => $vendedor, 'assistant_id' => $vendedor])
            ->assertStatus(422)->assertJsonValidationErrors('assistant_id');
    }

    /** Fase 29 — se puede quitar un mapeo; solo el Líder (o un superadmin) y los pedidos existentes no cambian. */
    public function test_se_puede_quitar_un_mapeo(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $assistantId = $this->userIdByEmail('asistentebodega2@test.com');
        WarehouseAssistantMapping::where('seller_user_id', $sellerId)->delete();
        $mapping = WarehouseAssistantMapping::create(['seller_user_id' => $sellerId, 'assistant_user_id' => $assistantId, 'is_active' => true]);

        $this->grantBodegaPermissions('asistentebodega@test.com');
        $this->withHeaders($this->authHeaders($this->loginAs('asistentebodega@test.com', 'asistentebodega@test.com', 'asistente_bodega')))
            ->deleteJson("/api/bodega/warehouse-assistant-mapping/{$mapping->id}")->assertStatus(403);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $headers = $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
        $this->withHeaders($headers)->deleteJson("/api/bodega/warehouse-assistant-mapping/{$mapping->id}")->assertOk();
        $this->withHeaders($headers)->deleteJson("/api/bodega/warehouse-assistant-mapping/{$mapping->id}")->assertStatus(404);

        $this->makeCurrentTenant();
        $this->assertSame(0, WarehouseAssistantMapping::where('seller_user_id', $sellerId)->where('is_active', true)->count());
    }

    /** Fase 29 — reasignar lo pendiente es una acción explícita: pasa al asistente de hoy los pedidos no entregados. */
    public function test_reasignar_pendientes_pasa_al_asistente_actual_solo_lo_no_entregado(): void
    {
        $sellerId = $this->userIdByEmail('vendedor6@test.com');
        $viejo = $this->userIdByEmail('asistentebodega2@test.com');
        $nuevo = $this->userIdByEmail('asistentebodega@test.com');
        WarehouseAssistantMapping::where('seller_user_id', $sellerId)->delete();
        WarehouseAssistantMapping::create(['seller_user_id' => $sellerId, 'assistant_user_id' => $nuevo, 'is_active' => true]);

        $pendiente = $this->makeOrderWithPipelineCard($sellerId, ['assistant_id' => $viejo]);
        $entregado = $this->makeOrderWithPipelineCard($sellerId, ['assistant_id' => $viejo, 'stage' => Order::STAGE_ENTREGADO]);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $headers = $this->authHeaders($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'));
        $this->withHeaders($headers)->postJson('/api/bodega/warehouse-assistant-mapping/reassign-pending', ['seller_id' => $sellerId])
            ->assertOk()->assertJsonPath('reasignados', 1);

        $this->makeCurrentTenant();
        $this->assertSame($nuevo, Order::find($pendiente->id)->assistant_id);
        $this->assertSame($viejo, Order::find($entregado->id)->assistant_id);
    }
}
