<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-454 (REQ-384, Bloque B4) — CRUD del catálogo de ubicaciones físicas de una bodega. */
class WarehouseLocationControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantBodega(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['bodega.read', 'bodega.write'])]);
    }

    public function test_index_lista_ubicaciones_activas_e_inactivas(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-01', 'is_active' => true]);
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-02', 'is_active' => false]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}/locations");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_bodega_inexistente_retorna_404(): void
    {
        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses/9999/locations')
            ->assertStatus(404);
    }

    public function test_store_crea_ubicacion_activa_por_defecto(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/warehouses/{$central->id}/locations", ['codigo' => 'A-1-03']);

        $response->assertStatus(201);
        $response->assertJsonPath('is_active', true);

        $this->reMakeCurrent();
        $this->assertSame(1, WarehouseLocation::where('warehouse_id', $central->id)->where('codigo', 'A-1-03')->count());
    }

    public function test_store_codigo_duplicado_en_la_misma_bodega_retorna_422(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-03', 'is_active' => true]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/warehouses/{$central->id}/locations", ['codigo' => 'A-1-03'])
            ->assertStatus(422);
    }

    /** Mismo código puede repetirse en OTRA bodega — el unique es por (warehouse_id, codigo). */
    public function test_store_mismo_codigo_en_otra_bodega_es_valido(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-03', 'is_active' => true]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/warehouses/{$merma->id}/locations", ['codigo' => 'A-1-03'])
            ->assertStatus(201);
    }

    public function test_update_permite_desactivar_sin_borrar(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $location = WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-03', 'is_active' => true]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$central->id}/locations/{$location->id}", ['is_active' => false]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_active', false);

        $this->reMakeCurrent();
        $this->assertSame(1, WarehouseLocation::where('id', $location->id)->count());
        $this->assertFalse(WarehouseLocation::find($location->id)->is_active);
    }

    public function test_update_permite_editar_codigo(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $location = WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-03', 'is_active' => true]);

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$central->id}/locations/{$location->id}", ['codigo' => 'A-1-04']);

        $response->assertStatus(200);
        $response->assertJsonPath('codigo', 'A-1-04');
    }

    public function test_update_ubicacion_inexistente_retorna_404(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$central->id}/locations/9999", ['is_active' => false])
            ->assertStatus(404);
    }
}
