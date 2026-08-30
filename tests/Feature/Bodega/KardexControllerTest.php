<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-467→472 (REQ-397→402) — pantalla "Movimientos de inventario" (Kardex). */
class KardexControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantBodega(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['bodega.read'])]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Candelabro de prueba', 'description' => 'Candelabro de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_index_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/kardex')
            ->assertStatus(403);
    }

    public function test_index_lista_movimientos_ordenados_del_mas_reciente(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $service = new KardexService();
        $service->registrar($product->id, $central->id, InventoryMovement::TIPO_ENTRADA, 20, 'Primero');
        $service->registrar($product->id, $central->id, InventoryMovement::TIPO_SALIDA, -5, 'Segundo');

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/kardex');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.referencia', 'Segundo');
        $response->assertJsonPath('data.0.saldo_inicial', 20);
        $response->assertJsonPath('data.0.saldo_resultante', 15);
        $response->assertJsonPath('data.1.referencia', 'Primero');
    }

    public function test_filtro_por_tipo_y_bodega_combinados(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $service = new KardexService();
        $service->registrar($product->id, $central->id, InventoryMovement::TIPO_DEVOLUCION, 3, 'Devolucion en Central');
        $service->registrar($product->id, $merma->id, InventoryMovement::TIPO_DEVOLUCION, 1, 'Devolucion en Merma');
        $service->registrar($product->id, $central->id, InventoryMovement::TIPO_AJUSTE, 2, 'Ajuste en Central');

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/kardex?tipo=Devolución&warehouse_id='.$central->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.referencia', 'Devolucion en Central');
    }

    public function test_buscador_por_producto(): void
    {
        $productA = $this->makeProduct(['reference' => 'CRISTAL-IMPERIAL']);
        $productB = $this->makeProduct(['reference' => 'BAUHAUS']);
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $service = new KardexService();
        $service->registrar($productA->id, $central->id, InventoryMovement::TIPO_ENTRADA, 5, 'A');
        $service->registrar($productB->id, $central->id, InventoryMovement::TIPO_ENTRADA, 5, 'B');

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/kardex?search=CRISTAL');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.producto.reference', 'CRISTAL-IMPERIAL');
    }

    public function test_sin_resultados_retorna_data_vacia_no_error(): void
    {
        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/kardex?search=ZZZZ_NO_EXISTE');

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_realizado_por_sistema_cuando_no_hay_usuario(): void
    {
        $product = $this->makeProduct();
        $central = Warehouse::where('name', 'Bodega Central')->first();
        (new KardexService())->registrar($product->id, $central->id, InventoryMovement::TIPO_ENTRADA, 5, 'Automatico');

        $this->grantBodega('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/kardex');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.realizado_por', 'Sistema');
    }
}
