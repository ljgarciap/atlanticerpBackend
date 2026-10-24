<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\CatalogProductLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-750 — "Loader-B" (destino). Criterio conservador: nunca pisa un `catalog_products` que ya
 * existía por otra vía que no sea este pipeline.
 */
final class CatalogProductLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_crea_catalog_product_nuevo_y_linkea_legacy_productos(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);

        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0),
        ]);

        $this->assertSame(1, $result['loaded']);
        $this->assertSame(0, $result['excluded']);
        $this->assertSame(0, $result['errors']);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(100.0, $catalogProduct->price_full);
        $this->assertSame('42016B-WH', $catalogProduct->factory_reference);

        $producto->refresh();
        $this->assertSame($catalogProduct->id, $producto->catalog_product_id);
    }

    public function test_crea_catalog_product_con_stock_inicial_de_al4(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);

        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0, stockInicial: 42.7),
        ]);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(43, $catalogProduct->stock_quantity); // redondeado desde el float de ICG
    }

    public function test_crea_catalog_product_preservando_stock_inicial_negativo(): void
    {
        // Decisión de negocio de Luis 2026-09-20 — 241 filas reales de AL4 en ICG vienen
        // negativas, se cargan tal cual, sin truncar a 0 ni excluir.
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);

        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0, stockInicial: -5.0),
        ]);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(-5, $catalogProduct->stock_quantity);
    }

    public function test_crea_catalog_product_con_stock_null_si_articulo_sin_fila_en_al4(): void
    {
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);

        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0),
        ]);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertNull($catalogProduct->stock_quantity);
    }

    // ── SCRUM-1114 — línea base de la sincronización de stock por delta ────────

    public function test_al_crear_un_producto_la_base_del_delta_nace_con_el_stock_inicial(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => false]);
        Producto::query()->create(['icg_codarticulo' => 1002, 'descripcion' => 'B', 'descatalogado' => false]);
        Producto::query()->create(['icg_codarticulo' => 1003, 'descripcion' => 'C', 'descatalogado' => false]);

        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 42.7),
            new MappedProducto(1002, 'ICG-1002', null, 'B', '12', 100.0, 60.0, stockInicial: -5.0),
            new MappedProducto(1003, 'ICG-1003', null, 'C', '12', 100.0, 60.0), // sin fila en AL4
        ]);

        $this->assertSame(43, Producto::query()->where('icg_codarticulo', 1001)->value('stock_al4_aplicado'));
        $this->assertSame(-5, Producto::query()->where('icg_codarticulo', 1002)->value('stock_al4_aplicado'));
        $this->assertSame(0, Producto::query()->where('icg_codarticulo', 1003)->value('stock_al4_aplicado')); // 0 conocido, no "nunca cargado"
    }

    public function test_re_sincronizar_nunca_mueve_la_base_del_delta(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => false]);
        (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 42.0)]);
        Producto::query()->where('icg_codarticulo', 1001)->update(['stock_al4_aplicado' => 30]); // el sync de stock ya la movió

        (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A v2', '12', 110.0, 65.0, stockInicial: 999.0)]);

        $this->assertSame(30, Producto::query()->where('icg_codarticulo', 1001)->value('stock_al4_aplicado'));
    }

    public function test_re_correr_nunca_toca_stock_quantity(): void
    {
        // AC central de SCRUM-921 — el seed es solo al crear; Bodega es la única fuente de verdad
        // de ahí en adelante, sin importar qué diga ICG en corridas futuras.
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0, stockInicial: 42.0),
        ]);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $catalogProduct->stock_quantity = 5; // Bodega ya tomó control del número real
        $catalogProduct->save();

        // Re-corrida real: ICG ahora reporta un stock AL4 completamente distinto.
        $loader->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED (v2)', '12', 110.0, 65.0, stockInicial: 999.0),
        ]);

        $catalogProduct->refresh();
        $this->assertSame(5, $catalogProduct->stock_quantity); // nunca se tocó
    }

    public function test_re_correr_actualiza_el_mismo_catalog_product_sin_duplicar(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0)]);

        $result = $loader->load([new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED (v2)', '12', 110.0, 65.0)]);

        $this->assertSame(1, $result['loaded']);
        $this->assertSame(1, CatalogProduct::query()->where('reference', 'ICG-1001')->count());

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $this->assertSame(110.0, $catalogProduct->price_full);
        $this->assertSame('Lámpara LED (v2)', $catalogProduct->name);
        $this->assertSame('42016B-WH', $catalogProduct->factory_reference);
    }

    public function test_re_correr_nunca_pisa_factory_reference_completado_a_mano(): void
    {
        // SCRUM-799 (2026-08-27) — desde el fix de ProductoMapper este pipeline solo manda
        // `factoryReference: null`; si el update siguiera pisando la columna, cualquier valor que
        // alguien complete a mano en Inventario después de la migración se perdería en la
        // siguiente corrida de legacy:sync-productos.
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([new MappedProducto(1001, '42016B-WH', null, 'Lámpara LED', '12', 100.0, 60.0)]);

        $catalogProduct = CatalogProduct::query()->where('reference', '42016B-WH')->firstOrFail();
        $catalogProduct->factory_reference = 'COMPLETADO-A-MANO';
        $catalogProduct->save();

        // Re-corrida real del pipeline: sigue mandando null (comportamiento post-fix).
        $loader->load([new MappedProducto(1001, '42016B-WH', null, 'Lámpara LED (v2)', '12', 110.0, 65.0)]);

        $catalogProduct->refresh();
        $this->assertSame('COMPLETADO-A-MANO', $catalogProduct->factory_reference);
        $this->assertSame('Lámpara LED (v2)', $catalogProduct->name);
    }

    public function test_excluye_sin_pisar_un_catalog_product_preexistente_no_creado_por_el_pipeline(): void
    {
        $existing = CatalogProduct::query()->create([
            'reference' => 'ICG-1001', 'name' => 'Producto real, cargado a mano',
            'price_full' => 999.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
            // catalog_product_id se queda NULL — este legacy.productos nunca fue linkeado a $existing.
        ]);

        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0),
        ]);

        $this->assertSame(0, $result['loaded']);
        $this->assertSame(1, $result['excluded']);

        $existing->refresh();
        $this->assertSame(999.0, $existing->price_full); // nunca se tocó
        $this->assertSame('Producto real, cargado a mano', $existing->name);
    }

    public function test_nunca_pisa_un_catalog_product_desactivado_localmente_aunque_ICG_lo_siga_reportando_activo(): void
    {
        // SCRUM-838 (2026-09-14) — un producto dado de baja en AtlanticERP (limpieza de productos en
        // desuso) nunca debe recibir refresh de name/brand/price_full/cost desde ICG, ni volver a
        // activarse solo. Requiere que la baja sea siempre is_active=false (desactivación lógica),
        // nunca borrado físico de la fila — ver docblock de CatalogProductLoader.
        $producto = Producto::query()->create([
            'icg_codarticulo' => 1001, 'descripcion' => 'Lámpara LED', 'descatalogado' => false,
        ]);
        $loader = new CatalogProductLoader();
        $loader->load([new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED', '12', 100.0, 60.0)]);

        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1001')->firstOrFail();
        $catalogProduct->is_active = false;
        $catalogProduct->save();

        // Re-corrida real: ICG sigue reportando el artículo activo, con precio/nombre distintos.
        $result = $loader->load([
            new MappedProducto(1001, 'ICG-1001', '42016B-WH', 'Lámpara LED (v2)', '12', 999.0, 500.0),
        ]);

        $this->assertSame(0, $result['loaded']);
        $this->assertSame(1, $result['excluded']);
        $this->assertSame('producto desactivado localmente (is_active=false) — no se resincroniza', $result['details'][0]['reason']);

        $catalogProduct->refresh();
        $this->assertFalse($catalogProduct->is_active);
        $this->assertSame(100.0, $catalogProduct->price_full); // nunca se tocó
        $this->assertSame('Lámpara LED', $catalogProduct->name); // nunca se tocó

        $producto->refresh();
        $this->assertSame($catalogProduct->id, $producto->catalog_product_id); // el link se preserva
    }

    public function test_items_ya_excluidos_por_el_mapper_se_cuentan_pero_no_se_procesan(): void
    {
        $result = (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', null, 'X', null, null, null, 'sin precio público (IDTARIFAV=1) en PRECIOSVENTA'),
        ]);

        $this->assertSame(0, $result['loaded']);
        $this->assertSame(1, $result['excluded']);
        $this->assertSame(0, CatalogProduct::query()->count());
    }

    // ── SCRUM-1016: el stock inicial también se refleja por bodega (Bodega Central) ─────────────────────────

    private function filasCentral(int $catalogProductId): array
    {
        $central = (int) \App\Modules\Bodega\Models\Warehouse::query()->where('name', 'Bodega Central')->value('id');

        return \App\Modules\Bodega\Models\ProductWarehouseStock::query()->where('catalog_product_id', $catalogProductId)->where('warehouse_id', $central)->pluck('quantity')->map(fn ($q): int => (int) $q)->all();
    }

    public function test_al_crear_un_producto_icg_con_stock_crea_la_fila_de_bodega_central_incluso_negativa(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => false]);
        Producto::query()->create(['icg_codarticulo' => 1002, 'descripcion' => 'B', 'descatalogado' => false]);
        Producto::query()->create(['icg_codarticulo' => 1003, 'descripcion' => 'C', 'descatalogado' => false]);

        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 42.7),
            new MappedProducto(1002, 'ICG-1002', null, 'B', '12', 100.0, 60.0, stockInicial: -5.0),
            new MappedProducto(1003, 'ICG-1003', null, 'C', '12', 100.0, 60.0, stockInicial: 0.0),
        ]);

        $this->assertSame([43], $this->filasCentral((int) CatalogProduct::query()->where('reference', 'ICG-1001')->value('id')));
        $this->assertSame([-5], $this->filasCentral((int) CatalogProduct::query()->where('reference', 'ICG-1002')->value('id')));
        $this->assertSame([], $this->filasCentral((int) CatalogProduct::query()->where('reference', 'ICG-1003')->value('id')), 'stock 0: sin fila');
    }

    public function test_re_sincronizar_nunca_toca_la_fila_de_bodega_central(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => false]);
        (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 42.0)]);
        $id = (int) CatalogProduct::query()->where('reference', 'ICG-1001')->value('id');
        \App\Modules\Bodega\Models\ProductWarehouseStock::query()->where('catalog_product_id', $id)->update(['quantity' => 7]); // Bodega ya tomó el control

        (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A v2', '12', 110.0, 65.0, stockInicial: 999.0)]);

        $this->assertSame([7], $this->filasCentral($id));
    }

    public function test_un_producto_creado_descatalogado_no_recibe_fila_de_bodega_central(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => true]);

        (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 42.0)]);

        $id = (int) CatalogProduct::query()->where('reference', 'ICG-1001')->value('id');
        $this->assertSame(42, (int) CatalogProduct::find($id)->stock_quantity);
        $this->assertSame([], $this->filasCentral($id));
    }

    public function test_sin_bodega_central_operativa_el_loader_crea_el_producto_sin_fila_y_sin_romper(): void
    {
        Producto::query()->create(['icg_codarticulo' => 1001, 'descripcion' => 'A', 'descatalogado' => false]);
        Producto::query()->create(['icg_codarticulo' => 1002, 'descripcion' => 'B', 'descatalogado' => false]);
        $central = (int) \App\Modules\Bodega\Models\Warehouse::query()->where('name', 'Bodega Central')->value('id');

        // Central solo_consulta => no operativa.
        \Illuminate\Support\Facades\DB::table('atlantic_bodega.warehouses')->where('id', $central)->update(['solo_consulta' => true]);
        \App\Modules\Bodega\Models\ProductWarehouseStock::olvidarBodegasSoloConsulta();
        $r = (new CatalogProductLoader())->load([new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 5.0)]);
        $this->assertSame(1, $r['loaded']);
        $this->assertSame(0, $r['errors']);

        // Central inexistente (renombrada).
        \Illuminate\Support\Facades\DB::table('atlantic_bodega.warehouses')->where('id', $central)->update(['solo_consulta' => false, 'name' => 'Otra']);
        $r2 = (new CatalogProductLoader())->load([new MappedProducto(1002, 'ICG-1002', null, 'B', '12', 100.0, 60.0, stockInicial: 5.0)]);
        $this->assertSame(1, $r2['loaded']);
        $this->assertSame(0, \App\Modules\Bodega\Models\ProductWarehouseStock::query()->whereIn('catalog_product_id', CatalogProduct::query()->whereIn('reference', ['ICG-1001', 'ICG-1002'])->pluck('id'))->count());
    }

    public function test_la_bodega_central_se_resuelve_una_vez_por_corrida_no_por_producto(): void
    {
        foreach ([1001, 1002, 1003] as $c) {
            Producto::query()->create(['icg_codarticulo' => $c, 'descripcion' => "P{$c}", 'descatalogado' => false]);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        (new CatalogProductLoader())->load([
            new MappedProducto(1001, 'ICG-1001', null, 'A', '12', 100.0, 60.0, stockInicial: 1.0),
            new MappedProducto(1002, 'ICG-1002', null, 'B', '12', 100.0, 60.0, stockInicial: 2.0),
            new MappedProducto(1003, 'ICG-1003', null, 'C', '12', 100.0, 60.0, stockInicial: 3.0),
        ]);
        $consultas = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'from "atlantic_bodega"."warehouses"') && str_contains($q, 'solo_consulta') && str_contains($q, '"name"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $consultas);
    }
}
