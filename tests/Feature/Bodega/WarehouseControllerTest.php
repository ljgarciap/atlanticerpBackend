<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-451→456 (REQ-381→386) — pantalla "Bodegas". */
class WarehouseControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** El cliente HTTP de test resetea el tenant "actual" al final de cada request. */
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

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Modelo Cristal Imperial', 'description' => 'Modelo Cristal Imperial',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 10, 'reorder_point' => 5,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * 7 bodegas físicas: las 6 originales de SCRUM-451 (sin la de consulta externa, retirada en la Fase 5b-2) más
     * "Reserva Servicios" (Grupo D parte 2, REQ-291, SCRUM-361).
     */
    public function test_index_lista_las_7_bodegas_fisicas(): void
    {
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses');

        $response->assertStatus(200);
        $response->assertJsonCount(7, 'data');
        $response->assertJsonPath('data.0.name', 'Bodega Central');
        $this->assertContains('Reserva Servicios', collect($response->json('data'))->pluck('name')->all());
    }

    public function test_index_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses')
            ->assertStatus(403);
    }

    public function test_show_solo_incluye_stock_positivo_de_esa_bodega(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $product = $this->makeProduct();
        $otherProduct = $this->makeProduct(['reference' => 'REF-'.uniqid()]);

        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-03', 'quantity' => 8]);
        ProductWarehouseStock::create(['catalog_product_id' => $otherProduct->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-04', 'quantity' => 0]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $merma->id, 'ubicacion' => 'M-1-01', 'quantity' => 2]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$central->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.ubicacion', 'A-1-03');
        // SCRUM-457 (REQ-387) — Bloque B4: el modal "Reubicar" del frontend arma
        // POST /bodega/relocations con este id, no con stock_id.
        $response->assertJsonPath('data.0.catalog_product_id', $product->id);
        $response->assertJsonPath('kpis.total_products', 1);
        $response->assertJsonPath('kpis.total_units', 8);
        // Mejora SCRUM-752 RN3 (Gerencia Test 2026-08-13) — unidades REALES de ESTA bodega (8 en
        // Central), no la suma global entre bodegas (8+2=10).
        $response->assertJsonPath('data.0.unidades_en_bodega', 8);

        $mermaResponse = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$merma->id}");
        $mermaResponse->assertJsonPath('data.0.unidades_en_bodega', 2);
    }

    public function test_columnas_globales_son_iguales_sin_importar_la_bodega_consultada(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $merma = Warehouse::where('name', 'Merma')->first();
        $product = $this->makeProduct(['stock_quantity' => 30, 'reorder_point' => 5]);

        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-03', 'quantity' => 20]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $merma->id, 'ubicacion' => 'M-1-01', 'quantity' => 10]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $fromCentral = $this->withHeaders($headers)->getJson("/api/bodega/warehouses/{$central->id}")->json('data.0');
        $this->reMakeCurrent();
        $fromMerma = $this->withHeaders($headers)->getJson("/api/bodega/warehouses/{$merma->id}")->json('data.0');

        $this->assertSame(30, $fromCentral['stock_total']);
        $this->assertSame(30, $fromMerma['stock_total']);
        $this->assertSame($fromCentral['disponible'], $fromMerma['disponible']);
        $this->assertSame($fromCentral['estado'], $fromMerma['estado']);
    }

    public function test_modo_pendiente_no_expone_ubicacion_inventada(): void
    {
        // Una bodega sin ubicación física confirmada (modo pendiente) se prueba con una bodega propia.
        $pendiente = Warehouse::create(['name' => 'Bodega pendiente de prueba', 'modo_detalle' => Warehouse::MODO_PENDIENTE]);
        $product = $this->makeProduct();
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $pendiente->id, 'ubicacion' => null, 'quantity' => 5]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$pendiente->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.ubicacion', null);
    }

    public function test_modo_cliente_expone_columna_cliente_en_vez_de_ubicacion(): void
    {
        $exhibicionCliente = Warehouse::where('name', 'Exhibición en cliente')->first();
        $cliente = MasterClient::create(['name' => 'Constructora Pacífico SA']);
        $product = $this->makeProduct();
        ProductWarehouseStock::create([
            'catalog_product_id' => $product->id, 'warehouse_id' => $exhibicionCliente->id,
            'cliente_id' => $cliente->id, 'quantity' => 1,
        ]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$exhibicionCliente->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.cliente', 'Constructora Pacífico SA');
        $response->assertJsonPath('data.0.ubicacion', null);
    }

    public function test_capacidad_pct_nula_se_expone_como_null_no_como_cero(): void
    {
        $pendiente = Warehouse::create(['name' => 'Bodega pendiente de prueba', 'modo_detalle' => Warehouse::MODO_PENDIENTE]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$pendiente->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('kpis.capacidad_pct', null);
    }

    public function test_filtro_por_categoria(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $family = CatalogProductFamily::create(['name' => 'Modelos y Colgantes', 'is_active' => true]);
        $matching = $this->makeProduct();
        $matching->families()->attach($family->id);
        $other = $this->makeProduct(['reference' => 'REF-'.uniqid()]);

        ProductWarehouseStock::create(['catalog_product_id' => $matching->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-03', 'quantity' => 5]);
        ProductWarehouseStock::create(['catalog_product_id' => $other->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-04', 'quantity' => 5]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?categoria={$family->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reference', $matching->reference);
    }

    public function test_filtro_por_ubicacion(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct(['reference' => 'REF-'.uniqid()]);
        ProductWarehouseStock::create(['catalog_product_id' => $productA->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-03', 'quantity' => 5]);
        ProductWarehouseStock::create(['catalog_product_id' => $productB->id, 'warehouse_id' => $central->id, 'ubicacion' => 'B-2-01', 'quantity' => 5]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?ubicacion=A-1-03");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reference', $productA->reference);
    }

    /**
     * SCRUM-454 (REQ-384, Bloque B4) — el chip ya no responde `not_implemented`: vacías =
     * `WarehouseLocation` activas MENOS las `ubicacion` ocupadas (quantity > 0) de esa bodega.
     * El shape de nivel superior sigue siendo el mismo (`warehouse`/`kpis`/`ubicaciones`/`data`) —
     * Pre-QA 2026-07-21 encontró CRÍTICO que faltaba `kpis` en la rama vieja y el frontend
     * crasheaba leyendo `data.kpis.responsable`.
     */
    public function test_chip_espacio_libre_expone_ubicaciones_vacias(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-01', 'is_active' => true]);
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-02', 'is_active' => true]);
        // A-1-02 está ocupada (quantity > 0) -> no debe aparecer como libre.
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'ubicacion' => 'A-1-02', 'quantity' => 5]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?chip=espacio_libre");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.ubicacion', 'A-1-01');
        $response->assertJsonPath('data.0.estado', 'disponible');
        $response->assertJsonStructure(['kpis' => ['responsable', 'total_products', 'total_units', 'capacidad_pct']]);
    }

    /** Ubicaciones inactivas no cuentan como "espacio libre" (fueron desactivadas a propósito). */
    public function test_chip_espacio_libre_ignora_ubicaciones_inactivas(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-01', 'is_active' => false]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?chip=espacio_libre");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    /** REQ-384 — el chip solo aplica a bodegas con ubicación física individual. */
    public function test_chip_espacio_libre_vacio_en_modo_pendiente(): void
    {
        $pendiente = Warehouse::create(['name' => 'Bodega pendiente de prueba', 'modo_detalle' => Warehouse::MODO_PENDIENTE]);
        WarehouseLocation::create(['warehouse_id' => $pendiente->id, 'codigo' => 'X-1', 'is_active' => true]);

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$pendiente->id}?chip=espacio_libre");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('ubicaciones', []);
    }

    public function test_show_bodega_inexistente_retorna_404(): void
    {
        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses/9999')
            ->assertStatus(404);
    }

    /**
     * SCRUM-754 (rebote de Gerencia 2026-08-18) — "Bodega → Bodegas" era el único listado sin
     * paginar del batch de estandarización. `per_page` respeta la página pedida y `meta` refleja
     * el total REAL (11), no solo lo devuelto en `data` (10) — cubre el bug de contar sobre la
     * página en vez del set filtrado completo. `per_page` usa 10 (mínimo de
     * `Pagination::ALLOWED_PER_PAGE`) — un valor fuera de esa lista cae al default (20).
     */
    public function test_show_pagina_los_resultados(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        foreach (range(1, 11) as $i) {
            $product = $this->makeProduct(['reference' => 'REF-PAG-'.$i]);
            ProductWarehouseStock::create([
                'catalog_product_id' => $product->id, 'warehouse_id' => $central->id,
                'ubicacion' => 'A-1-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'quantity' => 5,
            ]);
        }

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?per_page=10&page=1");

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.total', 11);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonPath('meta.last_page', 2);
        // Los KPIs de arriba siguen contando el set FILTRADO completo, no solo la página actual.
        $response->assertJsonPath('kpis.total_products', 11);

        $page2 = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?per_page=10&page=2");
        $page2->assertJsonCount(1, 'data');
    }

    /** Mismo mecanismo de paginación en la rama "espacio_libre" (ubicaciones vacías). */
    public function test_chip_espacio_libre_pagina_los_resultados(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        foreach (range(1, 11) as $i) {
            WarehouseLocation::create(['warehouse_id' => $central->id, 'codigo' => 'A-1-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'is_active' => true]);
        }

        $this->grantBodega('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/warehouses/{$central->id}?chip=espacio_libre&per_page=10&page=1");

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.total', 11);
        $response->assertJsonPath('meta.last_page', 2);
    }
}
