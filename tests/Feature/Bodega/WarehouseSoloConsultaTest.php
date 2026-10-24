<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Exceptions\WarehouseSoloConsultaException;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\BodegaReportsService;
use App\Modules\Bodega\Services\WarehouseStockService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 — "Bodega Zona Libre" es una bodega de SOLO CONSULTA (catálogo de Lafayette, no inventario de Atlantic):
 * no admite stock (modelo + trigger de BD), no aparece en ningún selector donde se escribe stock ni en los desgloses y
 * reportes, y los endpoints de escritura la rechazan con 422.
 */
final class WarehouseSoloConsultaTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function zonaLibre(): Warehouse
    {
        return Warehouse::query()->where('name', 'Bodega Zona Libre')->firstOrFail();
    }

    private function central(): Warehouse
    {
        return Warehouse::query()->where('name', 'Bodega Central')->firstOrFail();
    }

    private function makeProduct(): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-' . uniqid(), 'name' => 'Producto de prueba', 'description' => 'Producto de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    /** @param list<string> $perms */
    private function tokenWith(array $perms): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode($perms)]);

        return $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
    }

    public function test_solo_zona_libre_esta_marcada_solo_consulta(): void
    {
        $this->assertTrue($this->zonaLibre()->solo_consulta);
        $this->assertSame(0, Warehouse::query()->where('name', '<>', 'Bodega Zona Libre')->where('solo_consulta', true)->count());
        $this->assertSame(7, Warehouse::operativas()->count());
        $this->assertFalse(Warehouse::operativas()->pluck('name')->contains('Bodega Zona Libre'));
    }

    public function test_el_modelo_rechaza_crear_stock_en_zona_libre(): void
    {
        $this->expectException(WarehouseSoloConsultaException::class);

        ProductWarehouseStock::create(['catalog_product_id' => $this->makeProduct()->id, 'warehouse_id' => $this->zonaLibre()->id, 'quantity' => 5]);
    }

    public function test_el_modelo_rechaza_mover_una_fila_existente_hacia_zona_libre(): void
    {
        $row = ProductWarehouseStock::create(['catalog_product_id' => $this->makeProduct()->id, 'warehouse_id' => $this->central()->id, 'quantity' => 5]);

        $this->expectException(WarehouseSoloConsultaException::class);
        $row->update(['warehouse_id' => $this->zonaLibre()->id]);
    }

    public function test_el_trigger_de_bd_rechaza_insert_directo_por_sql(): void
    {
        $product = $this->makeProduct();
        $table = (new ProductWarehouseStock())->getTable();

        try {
            DB::transaction(fn () => DB::table($table)->insert([
                'catalog_product_id' => $product->id, 'warehouse_id' => $this->zonaLibre()->id, 'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('El trigger debía rechazar el INSERT directo en una bodega de solo consulta');
        } catch (QueryException $e) {
            $this->assertStringContainsString('solo consulta', $e->getMessage());
        }

        $this->assertSame(0, DB::table($table)->where('warehouse_id', $this->zonaLibre()->id)->count());
    }

    public function test_el_trigger_de_bd_rechaza_update_masivo_que_no_dispara_eventos_de_eloquent(): void
    {
        $row = ProductWarehouseStock::create(['catalog_product_id' => $this->makeProduct()->id, 'warehouse_id' => $this->central()->id, 'quantity' => 5]);
        $zl = $this->zonaLibre()->id;

        try {
            // `update()` sobre el Builder NO dispara el evento `saving` del modelo: solo lo frena el trigger.
            DB::transaction(fn () => ProductWarehouseStock::query()->whereKey($row->id)->update(['warehouse_id' => $zl]));
            $this->fail('El trigger debía rechazar el UPDATE masivo hacia una bodega de solo consulta');
        } catch (QueryException $e) {
            $this->assertStringContainsString('solo consulta', $e->getMessage());
        }

        $this->assertSame($this->central()->id, (int) $row->fresh()->warehouse_id);
    }

    public function test_el_trigger_no_bloquea_el_delete_que_necesita_la_limpieza(): void
    {
        $zl = $this->zonaLibre();
        $table = (new ProductWarehouseStock())->getTable();
        $product = $this->makeProduct();

        // Simula el estado histórico previo a SCRUM-990 (filas de Lafayette en Zona Libre): se abre la bodega un instante.
        $zl->update(['solo_consulta' => false]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $zl->id, 'quantity' => 9]);
        $zl->update(['solo_consulta' => true]);

        $this->assertSame(1, DB::table($table)->where('warehouse_id', $zl->id)->count());
        DB::table($table)->where('warehouse_id', $zl->id)->delete();
        $this->assertSame(0, DB::table($table)->where('warehouse_id', $zl->id)->count());
    }

    public function test_regla_operativa_acepta_bodegas_operativas_y_rechaza_zona_libre_e_inexistentes(): void
    {
        $rule = Warehouse::operativaRule();
        $ok = Validator::make(['w' => $this->central()->id], ['w' => [$rule]]);
        $zl = Validator::make(['w' => $this->zonaLibre()->id], ['w' => [$rule]]);
        $ghost = Validator::make(['w' => 999999], ['w' => [$rule]]);

        $this->assertFalse($ok->fails());
        $this->assertTrue($zl->fails());
        $this->assertTrue($ghost->fails());
        $this->assertStringContainsString('solo consulta', $zl->errors()->first('w'));
    }

    public function test_reubicacion_rechaza_zona_libre_como_origen_y_como_destino_con_mensaje(): void
    {
        // Los ids se resuelven ANTES de la primera petición HTTP: el login/la petición olvidan el tenant actual.
        $product = $this->makeProduct();
        $zl = $this->zonaLibre()->id;
        $central = $this->central()->id;
        $token = $this->tokenWith(['bodega.read', 'bodega.write']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id, 'origin_warehouse_id' => $zl, 'destination_warehouse_id' => $central, 'cantidad' => 1, 'motivo' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['origin_warehouse_id']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/relocations', [
            'catalog_product_id' => $product->id, 'origin_warehouse_id' => $central, 'destination_warehouse_id' => $zl, 'cantidad' => 1, 'motivo' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['destination_warehouse_id']);
    }

    public function test_conteo_general_rechaza_zona_libre(): void
    {
        $zl = $this->zonaLibre()->id;
        $token = $this->tokenWith(['bodega.read', 'bodega.write']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/general-counts', ['warehouse_id' => $zl])
            ->assertStatus(422)->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_solicitud_de_ajuste_rechaza_lineas_en_zona_libre(): void
    {
        $productId = $this->makeProduct()->id;
        $zl = $this->zonaLibre()->id;
        $token = $this->tokenWith(['bodega.read', 'bodega.write']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/bodega/adjustment-requests', [
            'catalog_product_id' => $productId,
            'lines' => [['warehouse_id' => $zl, 'tipo' => 'Sumar', 'cantidad' => 1, 'motivo' => 'OTRO_ESPECIFICAR']],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.warehouse_id']);
    }

    public function test_ingreso_de_compra_rechaza_linea_con_bodega_zona_libre(): void
    {
        $productId = $this->makeProduct()->id;
        $zl = $this->zonaLibre()->id;
        $token = $this->tokenWith(['compras.read', 'compras.edit']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/goods-receipts', [
            'lines' => [['catalog_product_id' => $productId, 'quantity' => 1, 'unit_cost' => 1, 'warehouse_id' => $zl]],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.warehouse_id']);
    }

    public function test_alta_de_producto_rechaza_stock_inicial_en_zona_libre(): void
    {
        $zl = $this->zonaLibre()->id;
        $token = $this->tokenWith(['compras.read', 'compras.write']);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/compras/inventory', [
            'initial_stock' => [['warehouse_id' => $zl, 'quantity' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors(['initial_stock.0.warehouse_id']);
    }

    public function test_selector_de_bodegas_de_compras_no_lista_zona_libre(): void
    {
        $token = $this->tokenWith(['compras.read']);

        $names = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/compras/warehouses')->assertOk()->json('data'))->pluck('name');

        $this->assertFalse($names->contains('Bodega Zona Libre'));
        $this->assertTrue($names->contains('Bodega Central'));
        $this->assertCount(7, $names);
    }

    public function test_desglose_de_stock_y_reporte_de_capacidad_excluyen_zona_libre(): void
    {
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $this->central()->id, 'quantity' => 12]);

        $breakdown = collect(app(WarehouseStockService::class)->breakdownForProduct($product->id)['warehouses']);
        $this->assertFalse($breakdown->pluck('name')->contains('Bodega Zona Libre'));
        $this->assertCount(7, $breakdown);

        $capacity = app(BodegaReportsService::class)->warehouseCapacity();
        $this->assertFalse(collect($capacity['warehouses'])->pluck('name')->contains('Bodega Zona Libre'));
        $this->assertSame(12, $capacity['total_units']);
    }
}
