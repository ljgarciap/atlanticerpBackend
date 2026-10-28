<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 8b — administración de almacenes por superadmin: alta, renombre, tipo, desactivación y baja protegida.
 */
class AdministrarAlmacenesFase8bTest extends FeatureTestCase
{
    private const URL = '/api/bodega/settings/warehouses';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function t(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        $headers = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $headers;
    }

    private function crear(string $name = 'Almacén norte', array $extra = []): int
    {
        return (int) $this->withHeaders($this->superadmin())->postJson(self::URL, ['name' => $name, ...$extra])->assertStatus(201)->json('id');
    }

    public function test_solo_superadmin_administra_almacenes(): void
    {
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson(self::URL, ['name' => 'X propio'])->assertStatus(403);
        $this->withHeaders($headers)->deleteJson(self::URL.'/1')->assertStatus(403);
    }

    public function test_alta_crea_un_almacen_activo_sin_funcion_ni_despacho(): void
    {
        $id = $this->crear('Almacén norte', ['tipo' => 'exhibicion', 'permite_venta' => true]);

        $this->t();
        $w = Warehouse::findOrFail($id);
        $this->assertTrue($w->is_active);
        $this->assertSame('exhibicion', $w->tipo);
        $this->assertTrue($w->permite_venta);
        $this->assertNull($w->funcion);
        $this->assertFalse($w->despacho_por_defecto);
        $this->t();
        $this->assertSame(1, Warehouse::query()->where('despacho_por_defecto', true)->count());
    }

    public function test_nombre_unico_en_alta_y_en_renombre(): void
    {
        $headers = $this->superadmin();
        $id = $this->crear('Almacén norte');

        $this->withHeaders($headers)->postJson(self::URL, ['name' => 'Almacén norte'])->assertStatus(422);
        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['name' => 'Merma'])->assertStatus(422);
        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['name' => 'Almacén sur', 'tipo' => 'exhibicion'])->assertStatus(200)
            ->assertJsonPath('name', 'Almacén sur')->assertJsonPath('tipo', 'exhibicion');
    }

    public function test_baja_de_un_almacen_vacio_lo_borra_con_sus_ubicaciones(): void
    {
        $id = $this->crear();
        $this->t();
        WarehouseLocation::query()->create(['warehouse_id' => $id, 'codigo' => 'A-1']);

        $this->withHeaders($this->superadmin())->deleteJson(self::URL."/{$id}")->assertStatus(204);

        $this->t();
        $this->assertNull(Warehouse::find($id));
        $this->t();
        $this->assertSame(0, WarehouseLocation::query()->where('warehouse_id', $id)->count());
    }

    public function test_la_baja_se_bloquea_para_sistema_defecto_stock_e_historial(): void
    {
        $headers = $this->superadmin();
        $this->t();
        $reserva = Warehouse::conFuncion(Warehouse::FUNCION_RESERVA_SERVICIOS)->id;
        $this->t();
        $central = Warehouse::despachoPorDefecto()->id;
        foreach ([$reserva, $central] as $id) {
            $this->withHeaders($headers)->deleteJson(self::URL."/{$id}")->assertStatus(422);
            $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['is_active' => false])->assertStatus(422);
        }

        $this->t();
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Modelo de prueba', 'description' => 'Modelo de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        // Con stock: no se borra ni se desactiva.
        $conStock = $this->crear('Con stock');
        $this->t();
        ProductWarehouseStock::query()->create(['catalog_product_id' => $product->id, 'warehouse_id' => $conStock, 'quantity' => 3]);
        $this->withHeaders($headers)->deleteJson(self::URL."/{$conStock}")->assertStatus(422);
        $this->withHeaders($headers)->putJson(self::URL."/{$conStock}", ['is_active' => false])->assertStatus(422);

        // Con historial (movimiento de inventario, FK sin cascada) y sin stock: no se borra, sí se desactiva.
        $conHistorial = $this->crear('Con historial');
        $this->t();
        DB::table('atlantic_bodega.inventory_movements')->insert([
            'catalog_product_id' => $product->id, 'warehouse_id' => $conHistorial, 'tipo' => 'entrada', 'cantidad' => 1,
            'referencia_texto' => 'test', 'saldo_inicial' => 0, 'saldo_resultante' => 1,
        ]);
        $this->withHeaders($headers)->deleteJson(self::URL."/{$conHistorial}")->assertStatus(422)->assertJsonPath('code', 'no_se_puede_borrar');
        $this->withHeaders($headers)->putJson(self::URL."/{$conHistorial}", ['is_active' => false])->assertStatus(200)->assertJsonPath('is_active', false);
    }

    public function test_un_almacen_desactivado_sale_de_selectores_y_reglas_de_venta(): void
    {
        $headers = $this->superadmin();
        $id = $this->crear('Almacén norte', ['permite_venta' => true]);
        $this->t();
        $this->assertContains($id, Warehouse::paraVenta()->pluck('id')->all());

        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['is_active' => false])->assertStatus(200)->assertJsonPath('permite_venta', false);

        $this->t();
        $this->assertNotContains($id, Warehouse::paraVenta()->pluck('id')->all());
        $this->assertNotContains($id, collect($this->withHeaders($headers)->getJson('/api/bodega/warehouses')->assertStatus(200)->json('data'))->pluck('id')->all());
        // El panel de administración sigue listándolo (para poder reactivarlo).
        $this->assertContains($id, collect($this->withHeaders($headers)->getJson(self::URL)->json('data'))->pluck('id')->all());

        // Desactivado no puede vender; reactivado sí.
        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['permite_venta' => true])->assertStatus(422);
        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['is_active' => true, 'permite_venta' => true])->assertStatus(200);
    }

    public function test_no_se_puede_operar_contra_un_almacen_desactivado(): void
    {
        $headers = $this->superadmin();
        $id = $this->crear('Almacén norte');
        $this->withHeaders($headers)->putJson(self::URL."/{$id}", ['is_active' => false])->assertStatus(200);

        $this->withHeaders($headers)->postJson('/api/bodega/general-counts', ['warehouse_id' => $id])
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
    }
}
