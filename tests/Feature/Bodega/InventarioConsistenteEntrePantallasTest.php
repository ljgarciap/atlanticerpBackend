<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-991 — el mismo dato de inventario debe verse igual en Compras → Inventario, Bodega → Ver Inventario y
 * Bodega → Vista Bodegas. Antes, Vista Bodegas contaba también los productos INACTIVOS con stock y daba N más que el
 * filtro por la misma bodega en las otras dos pantallas (3.354 contra 3.350 en dev), y ninguna pantalla mostraba el
 * número de productos con stock (el "total" es el tamaño del catálogo activo, incluye los de 0 unidades).
 */
class InventarioConsistenteEntrePantallasTest extends FeatureTestCase
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

    private function grantAmbos(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['bodega.read', 'compras.read'])]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto '.uniqid(), 'description' => 'Producto',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 10, 'reorder_point' => 5,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return array{central: Warehouse, merma: Warehouse} */
    private function sembrar(): array
    {
        $central = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $merma = Warehouse::where('name', 'Merma')->firstOrFail();

        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $inactivo = $this->makeProduct(['is_active' => false]);
        $sinUnidades = $this->makeProduct();

        ProductWarehouseStock::create(['catalog_product_id' => $a->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1', 'quantity' => 5]);
        ProductWarehouseStock::create(['catalog_product_id' => $b->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-2', 'quantity' => 3]);
        // Inactivo CON stock en Central: no debe contarse en ninguna pantalla (las listas lo ocultan por defecto).
        ProductWarehouseStock::create(['catalog_product_id' => $inactivo->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-3', 'quantity' => 7]);
        // Activo con 0 unidades: tampoco cuenta como "con stock".
        ProductWarehouseStock::create(['catalog_product_id' => $sinUnidades->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-4', 'quantity' => 0]);
        // El producto A también está en Merma: cuenta UNA vez (productos distintos, no filas).
        ProductWarehouseStock::create(['catalog_product_id' => $a->id, 'warehouse_id' => $merma->id, 'ubicacion' => 'M-1', 'quantity' => 2]);

        return ['central' => $central, 'merma' => $merma];
    }

    public function test_vista_bodegas_no_cuenta_productos_inactivos(): void
    {
        ['central' => $central] = $this->sembrar();
        $this->grantAmbos('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$central->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('kpis.total_products', 2);
        $response->assertJsonPath('kpis.total_units', 8); // 5 + 3, sin las 7 unidades del inactivo
    }

    public function test_bodega_central_da_el_mismo_numero_en_las_tres_pantallas(): void
    {
        ['central' => $central] = $this->sembrar();
        $this->grantAmbos('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $vista = $this->withHeaders($headers)->getJson("/api/bodega/warehouses/{$central->id}")->json('kpis.total_products');
        $this->reMakeCurrent();
        $compras = $this->withHeaders($headers)->getJson("/api/compras/inventory?warehouse_ids[]={$central->id}")->json('meta.total');
        $this->reMakeCurrent();
        $bodega = $this->withHeaders($headers)->getJson("/api/bodega/inventory?warehouse_id={$central->id}")->json('meta.total');

        $this->assertSame(2, $vista);
        $this->assertSame($vista, $compras, 'Compras → Inventario filtrado por Bodega Central');
        $this->assertSame($vista, $bodega, 'Bodega → Ver Inventario filtrado por Bodega Central');
    }

    public function test_kpi_con_stock_cuenta_productos_activos_distintos_con_unidades(): void
    {
        $this->sembrar();
        $this->grantAmbos('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $compras = $this->withHeaders($headers)->getJson('/api/compras/inventory')->assertStatus(200);
        $this->reMakeCurrent();
        $bodega = $this->withHeaders($headers)->getJson('/api/bodega/inventory')->assertStatus(200);

        // a (Central+Merma, cuenta una vez) + b = 2; sin el inactivo ni el de 0 unidades.
        $this->assertSame(2, $compras->json('kpis.with_stock'));
        $this->assertSame(2, $bodega->json('kpis.with_stock'));
        // El total sigue siendo el tamaño del catálogo activo (incluye los de 0 unidades): no cambia su significado.
        $this->assertGreaterThan($compras->json('kpis.with_stock'), $compras->json('kpis.total_products'));
    }
}
