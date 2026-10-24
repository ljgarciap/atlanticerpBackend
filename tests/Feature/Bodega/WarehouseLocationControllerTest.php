<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/** SCRUM-454 (REQ-384, Bloque B4) — CRUD del catálogo de ubicaciones físicas de una bodega. */
class WarehouseLocationControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantBodega(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['bodega.read', 'bodega.write'])]);
    }

    public function test_index_lista_ubicaciones_activas_e_inactivas(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-01', 'is_active' => true]);
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-02', 'is_active' => false]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}/locations");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_bodega_inexistente_retorna_404(): void
    {
        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses/9999/locations')
            ->assertStatus(404);
    }

    public function test_store_crea_ubicacion_activa_por_defecto(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

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

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

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

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/warehouses/{$merma->id}/locations", ['codigo' => 'A-1-03'])
            ->assertStatus(201);
    }

    public function test_update_permite_desactivar_sin_borrar(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $location = WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-03', 'is_active' => true]);

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

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

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$central->id}/locations/{$location->id}", ['codigo' => 'A-1-04']);

        $response->assertStatus(200);
        $response->assertJsonPath('codigo', 'A-1-04');
    }

    public function test_update_ubicacion_inexistente_retorna_404(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$central->id}/locations/9999", ['is_active' => false])
            ->assertStatus(404);
    }

    // ── SCRUM-990 (M2): Zona Libre es de solo consulta ───────────────────────────────────────────────────────

    public function test_crear_ubicacion_en_una_bodega_de_solo_consulta_responde_422_y_no_escribe_nada(): void
    {
        $zl = Warehouse::where('name', 'Bodega Zona Libre')->firstOrFail();
        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/warehouses/{$zl->id}/locations", ['codigo' => 'ZL-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id'])
            ->assertJsonPath('errors.warehouse_id.0', trans('bodega.warehouse_solo_consulta_ubicaciones'));

        $this->reMakeCurrent();
        $this->assertSame(0, WarehouseLocation::where('warehouse_id', $zl->id)->count());
    }

    public function test_editar_ubicacion_de_una_bodega_de_solo_consulta_responde_422_y_no_cambia_nada(): void
    {
        $zl = Warehouse::where('name', 'Bodega Zona Libre')->firstOrFail();
        $loc = WarehouseLocation::create(['warehouse_id' => $zl->id, 'codigo' => 'ZL-RESIDUAL', 'is_active' => true]);
        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/warehouses/{$zl->id}/locations/{$loc->id}", ['codigo' => 'ZL-X', 'is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);

        $this->reMakeCurrent();
        $fresh = WarehouseLocation::findOrFail($loc->id);
        $this->assertSame('ZL-RESIDUAL', $fresh->codigo);
        $this->assertTrue($fresh->is_active);
    }

    public function test_no_existe_ninguna_ruta_de_borrado_de_ubicaciones(): void
    {
        $metodos = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r): bool => str_contains($r->uri(), 'warehouses/{warehouseId}/locations'))
            ->flatMap(fn ($r): array => $r->methods())
            ->unique()->values()->all();

        $this->assertNotContains('DELETE', $metodos);
        $this->assertNotContains('PUT', $metodos);
    }
}
