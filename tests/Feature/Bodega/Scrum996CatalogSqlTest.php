<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-996 (auditoría SCRUM-995) — tres sitios que hidrataban el catálogo activo completo (~30K productos) en cada
 * petición y ahora resuelven en SQL: A) buscador de producto de "Nueva solicitud de ajuste", G) búsqueda dentro de
 * una bodega (`GET /warehouses/{id}?search=`) y C) tarjeta "Artículos críticos" del Home de Bodega.
 * El sitio B (lookup de Servicios) vive en `Tests\Feature\Servicios\ProductLookupSqlTest`.
 *
 * Cada bloque prueba (1) que el resultado es el que veía el usuario antes (relevancia, acentos, ubicación,
 * tolerancia a typos), (2) que no hay 5xx con entradas hostiles y (3) que ningún camino hidrata más
 * `CatalogProduct` que la página/tope pedido ni gasta decenas de MB con un catálogo grande.
 */
class Scrum996CatalogSqlTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private const EMAIL = 'liderbodega@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $this->grantBodegaPermissions(self::EMAIL, ['bodega.read']);

        return $this->authHeaders($this->loginAs(self::EMAIL, self::EMAIL, 'lider_bodega'));
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): CatalogProduct
    {
        return $this->makeCatalogProduct([
            'reference' => 'S996-' . uniqid(), 'name' => 'Producto S996', 'description' => 'Producto S996',
            'stock_quantity' => 10, 'reorder_point' => 3,
            ...$overrides,
        ]);
    }

    private function warehouse(string $name = 'Bodega Central'): Warehouse
    {
        return Warehouse::where('name', $name)->firstOrFail();
    }

    private function stock(CatalogProduct $p, Warehouse $w, int $qty, ?string $ubicacion = null): ProductWarehouseStock
    {
        return ProductWarehouseStock::create([
            'catalog_product_id' => $p->id, 'warehouse_id' => $w->id, 'quantity' => $qty, 'ubicacion' => $ubicacion,
        ]);
    }

    private function countRetrieved(callable $fn): int
    {
        $hydrated = 0;
        $active = true;
        CatalogProduct::retrieved(function () use (&$hydrated, &$active): void {
            if ($active) {
                $hydrated++;
            }
        });
        try {
            $fn();
        } finally {
            $active = false;
        }

        return $hydrated;
    }

    private function bulkProducts(int $n, bool $withStock = false, ?Warehouse $warehouse = null): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => 'BULK996-' . uniqid() . "-{$i}", 'name' => "Bulk lampara {$i}", 'description' => "Bulk {$i}",
                'price_full' => 10, 'cost' => 5, 'stock_quantity' => $i % 4 === 0 ? 0 : $i % 7, 'reorder_point' => 3,
                'rotation' => 'alta', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        if ($withStock && $warehouse !== null) {
            $ids = DB::table($table)->where('reference', 'like', 'BULK996-%')->pluck('id')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table((new ProductWarehouseStock())->getTable())->insert(array_map(fn (int $id): array => [
                    'catalog_product_id' => $id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'ubicacion' => 'B-' . ($id % 50),
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        }
    }

    // ── A) Buscador de "Nueva solicitud de ajuste" ────────────────────────────────────────────

    public function test_a_search_products_ordena_por_relevancia_y_tope_de_10(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->product(['reference' => "ZETA-LAMP-{$i}", 'name' => "Lampara zeta {$i}"]);
        }
        $exact = $this->product(['reference' => 'ZETA', 'name' => 'Otro producto']);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/bodega/adjustment-requests/products/search?search=zeta')
            ->assertStatus(200);

        $this->assertCount(10, $response->json('data'), 'mismo tope de 10 que antes');
        $this->assertSame($exact->id, $response->json('data.0.id'), 'la coincidencia exacta de referencia va primero');
        $this->assertSame(['id', 'reference', 'description'], array_keys($response->json('data.0')), 'mismo shape que antes');
    }

    public function test_a_search_products_ignora_acentos_y_mayusculas_y_excluye_inactivos(): void
    {
        $this->product(['reference' => 'ACENTO-1', 'name' => 'Lámpara colgante']);
        $this->product(['reference' => 'ACENTO-2', 'name' => 'Lámpara inactiva', 'is_active' => false]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/bodega/adjustment-requests/products/search?search=LAMPARA%20COLGANTE')
            ->assertStatus(200);

        $this->assertSame(['ACENTO-1'], array_column($response->json('data'), 'reference'));

        $inactive = $this->withHeaders($this->headers())
            ->getJson('/api/bodega/adjustment-requests/products/search?search=inactiva')
            ->assertStatus(200);
        $this->assertSame([], $inactive->json('data'));
    }

    public function test_a_search_products_tolera_un_typo_solo_si_no_hay_coincidencia_parcial(): void
    {
        $this->product(['reference' => 'CANDELABRO-1', 'name' => 'Candelabro cristal imperial']);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/bodega/adjustment-requests/products/search?search=cristl')
            ->assertStatus(200);

        $this->assertSame(['CANDELABRO-1'], array_column($response->json('data'), 'reference'));
    }

    public function test_a_search_products_con_parametros_hostiles_no_da_5xx(): void
    {
        $headers = $this->headers();
        foreach (['search[]=x', "search=%27", 'search=%25', 'search=_', 'search=%22%3B--', 'search=%20%20', 'search=' . str_repeat('a', 3000)] as $q) {
            $status = $this->withHeaders($headers)->getJson("/api/bodega/adjustment-requests/products/search?{$q}")->getStatusCode();
            $this->assertLessThan(500, $status, "search hostil: {$q}");
        }
    }

    public function test_a_search_products_no_hidrata_mas_de_10_modelos_con_un_catalogo_grande(): void
    {
        $this->bulkProducts(5000);
        $headers = $this->headers();

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(false);
        $response = null;
        $hydrated = $this->countRetrieved(function () use (&$response, $headers): void {
            $response = $this->withHeaders($headers)->getJson('/api/bodega/adjustment-requests/products/search?search=lampara');
        });
        $peakMb = (memory_get_peak_usage(false) - $before) / 1048576;

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertLessThanOrEqual(10, $hydrated, 'no debe hidratar más modelos que el tope de 10');
        $this->assertLessThan(64, $peakMb);
    }

    // ── G) Búsqueda dentro de una bodega ──────────────────────────────────────────────────────

    private function warehouseSearch(int $id, string $query, ?array $headers = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers ?? $this->headers())->getJson("/api/bodega/warehouses/{$id}?{$query}");
    }

    public function test_g_busca_por_referencia_descripcion_y_ubicacion_de_esa_bodega(): void
    {
        $central = $this->warehouse();
        $otra = $this->warehouse('Merma');
        $porRef = $this->product(['reference' => 'ALFA-77', 'name' => 'Aplique pared', 'description' => 'Aplique pared']);
        $porDesc = $this->product(['reference' => 'BETA-88', 'name' => 'Riel', 'description' => 'Riel magnetico con porcelana blanca']);
        $porUbic = $this->product(['reference' => 'GAMA-99', 'name' => 'Spot', 'description' => 'Spot empotrable']);
        $ajeno = $this->product(['reference' => 'ALFA-00', 'name' => 'Aplique ajeno', 'description' => 'Aplique ajeno']);
        $this->stock($porRef, $central, 4, 'A-1-01');
        $this->stock($porDesc, $central, 6, 'A-1-02');
        $this->stock($porUbic, $central, 8, 'Z-9-99');
        $this->stock($ajeno, $otra, 5, 'M-1-01');

        $headers = $this->headers();

        $r = $this->warehouseSearch($central->id, 'search=alfa', $headers)->assertStatus(200);
        $this->assertSame(['ALFA-77'], array_column($r->json('data'), 'reference'), 'no debe traer el producto de otra bodega');

        $r = $this->warehouseSearch($central->id, 'search=PORCELANA', $headers)->assertStatus(200);
        $this->assertSame(1, $r->json('meta.total'), 'coincide con la descripción, sin distinguir mayúsculas');

        $r = $this->warehouseSearch($central->id, 'search=z-9-99', $headers)->assertStatus(200);
        $this->assertSame(1, $r->json('meta.total'), 'coincide con la ubicación de la fila');
    }

    public function test_g_kpis_y_meta_reflejan_el_filtro_de_texto(): void
    {
        $central = $this->warehouse();
        $a = $this->product(['reference' => 'KPI-A', 'name' => 'Kpi lampara uno']);
        $b = $this->product(['reference' => 'KPI-B', 'name' => 'Kpi lampara dos']);
        $c = $this->product(['reference' => 'OTRO-C', 'name' => 'Sin relacion']);
        $this->stock($a, $central, 4, 'K-1');
        $this->stock($b, $central, 6, 'K-2');
        $this->stock($c, $central, 90, 'K-3');

        $r = $this->warehouseSearch($central->id, 'search=kpi')->assertStatus(200);

        $this->assertSame(2, $r->json('kpis.total_products'));
        $this->assertSame(10, $r->json('kpis.total_units'));
        $this->assertSame(2, $r->json('meta.total'));
    }

    public function test_g_varias_palabras_pueden_repartirse_entre_producto_y_ubicacion(): void
    {
        $central = $this->warehouse();
        $p = $this->product(['reference' => 'MULTI-1', 'name' => 'Candelabro imperial']);
        $q = $this->product(['reference' => 'MULTI-2', 'name' => 'Candelabro moderno']);
        $this->stock($p, $central, 3, 'X-5-05');
        $this->stock($q, $central, 3, 'Y-6-06');

        $r = $this->warehouseSearch($central->id, 'search=' . rawurlencode('candelabro x-5-05'))->assertStatus(200);

        $this->assertSame(1, $r->json('meta.total'), 'palabra del producto + palabra de la ubicación, en cualquier orden');
    }

    public function test_g_ordena_por_relevancia_exacto_primero_y_pagina_sin_repetir(): void
    {
        $central = $this->warehouse();
        for ($i = 1; $i <= 12; $i++) {
            $this->stock($this->product(['reference' => "REL-LAMP-{$i}", 'name' => "Lampara relevante {$i}"]), $central, 2, "R-{$i}");
        }
        $exact = $this->product(['reference' => 'REL', 'name' => 'Otro']);
        $this->stock($exact, $central, 2, 'R-99');

        $headers = $this->headers();
        $page1 = $this->warehouseSearch($central->id, 'search=rel&per_page=10&page=1', $headers)->assertStatus(200);
        $page2 = $this->warehouseSearch($central->id, 'search=rel&per_page=10&page=2', $headers)->assertStatus(200);

        $ids1 = array_column($page1->json('data'), 'stock_id');
        $ids2 = array_column($page2->json('data'), 'stock_id');
        $this->assertSame(13, $page1->json('meta.total'));
        $this->assertCount(10, $ids1);
        $this->assertCount(3, $ids2);
        $this->assertSame([], array_intersect($ids1, $ids2), 'sin repetidos entre páginas');
        $refs1 = array_column($page1->json('data'), 'reference');
        $this->assertSame('REL', $refs1[0], 'la coincidencia exacta de referencia va primero');
    }

    public function test_g_tolera_un_typo_solo_si_no_hay_coincidencia_parcial_y_dentro_de_la_bodega(): void
    {
        $central = $this->warehouse();
        $otra = $this->warehouse('Merma');
        $mine = $this->product(['reference' => 'TYPO-1', 'name' => 'Candelabro cristal imperial']);
        $other = $this->product(['reference' => 'TYPO-2', 'name' => 'Candelabro cristal barroco']);
        $this->stock($mine, $central, 3, 'T-1');
        $this->stock($other, $otra, 3, 'T-2');

        $r = $this->warehouseSearch($central->id, 'search=cristl')->assertStatus(200);

        $this->assertSame(1, $r->json('meta.total'));
        $this->assertSame('TYPO-1', $r->json('data.0.reference'));
    }

    public function test_g_sin_coincidencias_devuelve_vacio_y_parametros_hostiles_no_dan_5xx(): void
    {
        $central = $this->warehouse();
        $this->stock($this->product(), $central, 3, 'H-1');
        $headers = $this->headers();

        $empty = $this->warehouseSearch($central->id, 'search=zzzqqqxxx', $headers)->assertStatus(200);
        $this->assertSame(0, $empty->json('meta.total'));
        $this->assertSame([], $empty->json('data'));

        foreach (['search[]=x', 'search=%27', 'search=%25', 'search=_', 'search=%22%3B--', 'search=%20%20', 'search=' . str_repeat('a', 3000)] as $q) {
            $this->assertLessThan(500, $this->warehouseSearch($central->id, $q, $headers)->getStatusCode(), "search hostil: {$q}");
        }
    }

    public function test_g_no_hidrata_mas_modelos_que_la_pagina_ni_gasta_decenas_de_mb(): void
    {
        $central = $this->warehouse();
        $this->bulkProducts(5000, withStock: true, warehouse: $central);
        $headers = $this->headers();

        foreach (['search=lampara', 'search=zzzqqq', 'search=lampara&per_page=100'] as $q) {
            memory_reset_peak_usage();
            $before = memory_get_peak_usage(false);
            $response = null;
            $hydrated = $this->countRetrieved(function () use (&$response, $central, $headers, $q): void {
                $response = $this->warehouseSearch($central->id, $q, $headers);
            });
            $peakMb = (memory_get_peak_usage(false) - $before) / 1048576;

            $response->assertStatus(200);
            $this->assertLessThanOrEqual(100, $hydrated, "no debe hidratar más modelos que la página ({$q})");
            $this->assertLessThan(64, $peakMb, "pico de memoria < 64 MB ({$q})");
        }
    }

    // ── C) Tarjeta "Artículos críticos" del Home de Bodega ────────────────────────────────────

    /** @return array<string, mixed> */
    private function homeSummary(): array
    {
        return $this->withHeaders($this->headers())->getJson('/api/bodega/home/summary')->assertStatus(200)->json();
    }

    private function inventoryCriticosTotal(): int
    {
        return (int) $this->withHeaders($this->headers())->getJson('/api/bodega/inventory?page=1&per_page=20&chip=criticos')
            ->assertStatus(200)->json('meta.total');
    }

    public function test_c_el_total_del_home_coincide_con_el_chip_criticos_del_inventario(): void
    {
        $excluida = Warehouse::firstOrCreate(['name' => 'S996 excluida'], ['excluye_disponible' => true]);
        $excluida->forceFill(['excluye_disponible' => true])->save();

        $this->product(['stock_quantity' => 0, 'reorder_point' => 5]);                       // sin stock
        $this->product(['stock_quantity' => 4, 'reorder_point' => 10]);                      // bajo stock
        $this->product(['stock_quantity' => 50, 'reorder_point' => 3]);                      // disponible
        $this->product(['stock_quantity' => 0, 'reorder_point' => 5, 'is_active' => false]); // inactivo: no cuenta
        // El compromiso a un pedido de Bodega lo deja sin stock (5 - 5 = 0): es lo que el Home ignoraba (O1).
        $comprometido = $this->product(['stock_quantity' => 5, 'reorder_point' => 3]);
        $this->makeOrder(['stage' => Order::STAGE_ASIGNADO], ['catalog_product_id' => $comprometido->id, 'qty_requested' => 5, 'qty_delivered' => 0]);
        // Stock en bodega excluida: 20 - 18 = 2 <= reorder 5 → bajo stock.
        $enExcluida = $this->product(['stock_quantity' => 20, 'reorder_point' => 5]);
        $this->stock($enExcluida, $excluida, 18);

        $home = $this->homeSummary();

        $this->assertSame($this->inventoryCriticosTotal(), $home['articulos_criticos']['total'], 'Home = chip criticos del Inventario (O1)');
        $this->assertSame(4, $home['articulos_criticos']['total']);
    }

    public function test_c_items_topn_ordenados_sin_stock_primero_y_luego_menor_disponible(): void
    {
        $sin = $this->product(['reference' => 'C-SIN', 'stock_quantity' => 0, 'reorder_point' => 5]);
        for ($i = 1; $i <= 6; $i++) {
            $this->product(['reference' => "C-BAJO-{$i}", 'stock_quantity' => $i, 'reorder_point' => 10]);
        }

        $home = $this->homeSummary();
        $items = $home['articulos_criticos']['items'];

        $this->assertCount(5, $items, 'tope configurable (default 5)');
        $this->assertSame(7, $home['articulos_criticos']['total']);
        $this->assertSame($sin->id, $items[0]['catalog_product_id']);
        $this->assertSame('sin_stock', $items[0]['estado']);
        $this->assertSame(['bajo_stock', 'bajo_stock', 'bajo_stock', 'bajo_stock'], array_column(array_slice($items, 1), 'estado'));
        $this->assertSame([1, 2, 3, 4], array_column(array_slice($items, 1), 'disponible'), 'menor disponible primero');
        $this->assertSame(['catalog_product_id', 'factory_reference', 'reference', 'description', 'disponible', 'reorder_point', 'estado'], array_keys($items[0]));
    }

    public function test_c_sin_criticos_devuelve_lista_vacia_y_total_cero(): void
    {
        $this->product(['stock_quantity' => 100, 'reorder_point' => 3]);

        $home = $this->homeSummary();

        $this->assertSame(['items' => [], 'total' => 0], $home['articulos_criticos']);
    }

    public function test_c_home_no_hidrata_mas_que_el_topn_con_un_catalogo_grande(): void
    {
        $this->bulkProducts(5000);
        $headers = $this->headers();

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(false);
        $response = null;
        $hydrated = $this->countRetrieved(function () use (&$response, $headers): void {
            $response = $this->withHeaders($headers)->getJson('/api/bodega/home/summary');
        });
        $peakMb = (memory_get_peak_usage(false) - $before) / 1048576;

        $response->assertStatus(200);
        $this->assertGreaterThan(1000, $response->json('articulos_criticos.total'));
        $this->assertCount(5, $response->json('articulos_criticos.items'));
        // El resto del summary (rotación, etc.) puede tocar algunos productos: el punto es que NO sean miles.
        $this->assertLessThanOrEqual(50, $hydrated, 'el Home no debe hidratar el catálogo activo completo');
        $this->assertLessThan(64, $peakMb);
    }
}
