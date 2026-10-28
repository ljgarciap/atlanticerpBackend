<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

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
}
