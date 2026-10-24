<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Contracts\DescatalogadoStockIcgExtractorContract;
use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Services\DescatalogadoEquivalenciaService;
use App\Modules\Legacy\Services\ProductoMapper;
use App\Modules\Legacy\Services\StockDeltaService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1136 F5 — artículos descatalogados de ICG con stock: staging (`legacy:sync-productos-descatalogados`), equivalencia por referencia, creación de
 * productos inactivos (`legacy:crear-descatalogados-inactivos`) y verificación (`legacy:verify-descatalogados`). Todo con fixtures: nunca ICG ni red.
 *
 * Los comandos hacen `Tenant::forgetCurrent()` en su `finally`: cada llamada a Artisan deja el tenant apagado, `reactivateTenant()` lo vuelve a prender.
 */
final class DescatalogadosConStockTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $opts */
    private function correr(string $comando, array $opts = []): string
    {
        $code = Artisan::call($comando, ['--tenant' => 'atlantic', ...$opts]);
        $this->reactivateTenant();
        $out = Artisan::output();
        $this->assertContains($code, [0, 1], $out);
        $this->ultimoExit = $code;

        return $out;
    }

    private int $ultimoExit = 0;

    private function stock(int $codarticulo, string $almacen, float $stock): void
    {
        DB::table('atlantic_legacy.stock_almacen')->insert([
            'codarticulo' => $codarticulo, 'talla' => '.', 'color' => '.', 'codalmacen' => $almacen, 'stock' => $stock,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activo(int $cod, string $ref, ?CatalogProduct $catalogo = null): Producto
    {
        return Producto::query()->create([
            'icg_codarticulo' => $cod, 'descripcion' => "Activo {$cod}", 'ref_proveedor' => $ref, 'descatalogado' => false,
            'catalog_product_id' => $catalogo?->id,
        ]);
    }

    private function catalogo(string $reference, bool $activo = true): CatalogProduct
    {
        return CatalogProduct::query()->create(['reference' => $reference, 'name' => "Catálogo {$reference}", 'price_full' => 10.0, 'is_active' => $activo]);
    }

    /** @param list<array{0: int, 1: string|null}> $articulos [codarticulo, REFPROVEEDOR] */
    private function extraccion(array $articulos): ExtractedProductos
    {
        $productos = $precios = $costos = [];
        foreach ($articulos as [$cod, $ref]) {
            $productos[] = new ProductoRow($cod, "Descatalogado {$cod}", '3/7', '12', $ref, true, stockAl4: $cod === 5001 ? 4.0 : null);
            $precios[]   = new ProductoPrecioRow($cod, 1, 100.0 + $cod, 119.0, 0.0, false);
            $costos[]    = new ProductoCostoRow($cod, 55, 60.0);
        }

        return new ExtractedProductos($productos, $precios, $costos);
    }

    private function fakeExtractor(?ExtractedProductos $r = null, ?\Throwable $throws = null): void
    {
        $this->app->instance(ProductoDescatalogadoExtractorContract::class, new FakeProductoDescatalogadoExtractor($r, $throws));
    }

    // ── RF1 — staging ──────────────────────────────────────────────────────────

    public function test_dry_run_no_escribe_nada_en_staging(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A'], [5002, 'REF-B']]));

        $out = $this->correr('legacy:sync-productos-descatalogados', ['--dry-run' => true]);

        $this->assertSame(0, $this->ultimoExit);
        $this->assertStringContainsString('DRY-RUN', $out);
        $this->assertSame(0, Producto::query()->count());
        $this->assertSame(0, ProductoPrecio::query()->count());
        $this->assertSame(0, ProductoCosto::query()->count());
    }

    public function test_stagea_productos_precios_y_costos_con_descatalogado_true(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A'], [5002, 'REF-B']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $this->assertSame(0, $this->ultimoExit);
        $this->assertSame(2, Producto::query()->where('descatalogado', true)->count());
        $this->assertSame(2, ProductoPrecio::query()->count());
        $this->assertSame(2, ProductoCosto::query()->count());
        $this->assertSame(0, CatalogProduct::query()->count(), 'el staging nunca escribe en catalog_products');
        $this->assertSame('REF-A', Producto::query()->where('icg_codarticulo', 5001)->value('ref_proveedor'));
    }

    public function test_re_correr_el_staging_no_duplica_ni_cambia_los_conteos(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A'], [5002, 'REF-B']]));

        $this->correr('legacy:sync-productos-descatalogados');
        $out = $this->correr('legacy:sync-productos-descatalogados');

        $this->assertSame(0, $this->ultimoExit);
        $this->assertSame(2, Producto::query()->count());
        $this->assertSame(2, ProductoPrecio::query()->count());
        $this->assertSame(2, ProductoCosto::query()->count());
        $this->assertMatchesRegularExpression('/nuevos en legacy\.productos\s*\|\s*0/', $out);
    }

    public function test_aborta_sin_escribir_si_el_extracto_trae_un_articulo_activo(): void
    {
        $r = new ExtractedProductos(
            [new ProductoRow(5001, 'Desc', null, null, 'A', true), new ProductoRow(5002, 'Activo', null, null, 'B', false)],
            [],
            [],
        );
        $this->fakeExtractor($r);

        $this->correr('legacy:sync-productos-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
        $this->assertSame(0, Producto::query()->count());
    }

    public function test_falla_de_extraccion_devuelve_error_sin_escribir(): void
    {
        $this->fakeExtractor(null, new RuntimeException('sin conexión'));

        $this->correr('legacy:sync-productos-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
        $this->assertSame(0, Producto::query()->count());
    }

    public function test_el_staging_de_descatalogados_no_pisa_un_activo_existente(): void
    {
        $activo = $this->activo(100, 'ACT-1', $this->catalogo('ACT-1'));
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $activo->refresh();
        $this->assertFalse($activo->descatalogado);
        $this->assertNotNull($activo->catalog_product_id);
    }

    // ── RF2 — equivalencia por referencia ─────────────────────────────────────

    public function test_equivalencia_por_referencia_recortada_y_sin_distinguir_mayusculas(): void
    {
        $cp = $this->catalogo('ABC-123');
        $this->activo(100, 'ABC-123', $cp);
        $this->fakeExtractor($this->extraccion([[5001, '  abc-123 '], [5002, 'OTRA']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $equivalente = Producto::query()->where('icg_codarticulo', 5001)->firstOrFail();
        $this->assertSame(100, $equivalente->equivalente_icg_codarticulo);
        $this->assertNull($equivalente->catalog_product_id, 'H1: el equivalente NO guarda catálogo propio, se resuelve por el vínculo');
        $this->assertSame($cp->id, app(DescatalogadoEquivalenciaService::class)->catalogoResuelto(5001));
        $this->assertSame(1, CatalogProduct::query()->count(), 'no se crea ningún catalog_products para el equivalente');

        $otro = Producto::query()->where('icg_codarticulo', 5002)->firstOrFail();
        $this->assertNull($otro->equivalente_icg_codarticulo);
        $this->assertNull($otro->catalog_product_id);
    }

    public function test_equivalencia_es_idempotente(): void
    {
        $this->activo(100, 'ABC-123', $this->catalogo('ABC-123'));
        $this->fakeExtractor($this->extraccion([[5001, 'abc-123']]));

        $this->correr('legacy:sync-productos-descatalogados');
        $out = $this->correr('legacy:sync-productos-descatalogados');

        $this->assertMatchesRegularExpression('/filas que cambiaron al vincular\s*\|\s*0/', $out);
    }

    public function test_varios_activos_con_la_misma_referencia_son_ambiguos_aunque_solo_uno_tenga_catalogo(): void
    {
        $this->activo(100, 'DUP-2', $this->catalogo('DUP-2'));
        $this->activo(101, 'dup-2', null);
        $this->fakeExtractor($this->extraccion([[5001, 'DUP-2']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $this->assertNull(Producto::query()->where('icg_codarticulo', 5001)->value('equivalente_icg_codarticulo'));
    }

    public function test_referencias_genericas_o_sin_alfanumericos_no_cuentan_como_referencia(): void
    {
        foreach (['-', '.', '  ', '///', 'N/A', 'n/a', 'S/R'] as $generica) {
            $this->assertNull(DescatalogadoEquivalenciaService::normalizar($generica), "'{$generica}' no es una referencia útil");
        }
        $this->assertSame('abc-1', DescatalogadoEquivalenciaService::normalizar(' ABC-1 '));

        // Aunque un activo comparta la referencia genérica, no se vincula.
        $this->activo(100, '-', $this->catalogo('GEN-1'));
        $this->activo(101, 'N/A', $this->catalogo('GEN-2'));
        $this->fakeExtractor($this->extraccion([[5001, '-'], [5002, 'n/a']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $this->assertSame(0, Producto::query()->whereNotNull('equivalente_icg_codarticulo')->count());
    }

    public function test_el_staging_imprime_los_pares_descatalogado_activo_en_dry_run_y_real(): void
    {
        $cp = $this->catalogo('PAR-1');
        $this->activo(100, 'PAR-1', $cp);
        $this->fakeExtractor($this->extraccion([[5001, 'par-1']]));

        foreach ([true, false] as $dry) {
            $out = $this->correr('legacy:sync-productos-descatalogados', $dry ? ['--dry-run' => true] : []);
            $this->assertStringContainsString('Pares descatalogado -> activo', $out);
            $this->assertMatchesRegularExpression('/5001\s*\|\s*par-1\s*\|\s*100\s*\|\s*' . $cp->id . '/', $out);
        }
    }

    public function test_un_codigo_que_ya_es_activo_en_staging_no_se_convierte_en_descatalogado(): void
    {
        // H2: MISMO icg_codarticulo que un activo ya staged.
        $cp = $this->catalogo('MISMO-1');
        $activo = $this->activo(5001, 'MISMO-1', $cp);
        $this->fakeExtractor($this->extraccion([[5001, 'MISMO-1'], [5002, 'OTRO-1']]));

        foreach ([true, false] as $dry) {
            $out = $this->correr('legacy:sync-productos-descatalogados', $dry ? ['--dry-run' => true] : []);
            $this->assertSame(0, $this->ultimoExit);
            $this->assertMatchesRegularExpression('/ya está en staging como ACTIVO[^|]*\|\s*1/', $out);
            $this->assertStringContainsString('Omitidos por ser ACTIVOS en staging (icg_codarticulo): 5001', $out);
        }

        $activo->refresh();
        $this->assertFalse($activo->descatalogado);
        $this->assertSame($cp->id, $activo->catalog_product_id);
        $this->assertSame('Activo 5001', $activo->descripcion, 'ni siquiera se actualizan sus datos');
        $this->assertTrue(Producto::query()->where('icg_codarticulo', 5002)->value('descatalogado'));
    }

    public function test_equivalencia_ambigua_no_se_vincula(): void
    {
        $this->activo(100, 'DUP-1', $this->catalogo('DUP-1'));
        $this->activo(101, 'dup-1', $this->catalogo('DUP-1b'));
        $this->fakeExtractor($this->extraccion([[5001, 'DUP-1']]));

        $out = $this->correr('legacy:sync-productos-descatalogados');

        $p = Producto::query()->where('icg_codarticulo', 5001)->firstOrFail();
        $this->assertNull($p->equivalente_icg_codarticulo);
        $this->assertNull($p->catalog_product_id);
        $this->assertMatchesRegularExpression('/ambigua[^|]*\|\s*1/', $out);
    }

    public function test_equivalente_con_activo_aun_sin_catalogo_deja_el_vinculo_y_el_catalogo_nulo(): void
    {
        $this->activo(100, 'ABC-123', null);
        $this->fakeExtractor($this->extraccion([[5001, 'ABC-123']]));

        $this->correr('legacy:sync-productos-descatalogados');

        $p = Producto::query()->where('icg_codarticulo', 5001)->firstOrFail();
        $this->assertSame(100, $p->equivalente_icg_codarticulo);
        $this->assertNull($p->catalog_product_id);
    }

    // ── RF3 / RF4 — crear inactivos ────────────────────────────────────────────

    private function stagearConStock(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A'], [5002, 'REF-B'], [5003, null], [5004, 'ACT-1']]));
        $this->activo(100, 'ACT-1', $this->catalogo('ACT-1'));
        $this->correr('legacy:sync-productos-descatalogados');
        foreach ([5001, 5002, 5003, 5004] as $c) {
            $this->stock($c, 'AL2', 10.0);
        }
        $this->stock(5001, 'A1', 2.5);
    }

    public function test_dry_run_por_defecto_informa_pero_no_crea(): void
    {
        $this->stagearConStock();
        $antes = CatalogProduct::query()->count();

        $out = $this->correr('legacy:crear-descatalogados-inactivos');

        $this->assertSame(0, $this->ultimoExit);
        $this->assertStringContainsString('DRY-RUN', $out);
        $this->assertMatchesRegularExpression('/A CREAR como inactivos\s*\|\s*2/', $out);
        $this->assertSame($antes, CatalogProduct::query()->count());
        $this->assertNull(Producto::query()->where('icg_codarticulo', 5001)->value('catalog_product_id'));
    }

    public function test_apply_exige_operador(): void
    {
        $this->stagearConStock();

        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true]);

        $this->assertSame(1, $this->ultimoExit);
        $this->assertSame(1, CatalogProduct::query()->count()); // solo el 'ACT-1' del activo
    }

    public function test_apply_crea_inactivos_con_datos_del_mapeo_y_sin_stock(): void
    {
        $this->stagearConStock();

        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertSame(0, $this->ultimoExit);
        $creado = CatalogProduct::query()->where('reference', 'REF-A')->firstOrFail();
        $this->assertFalse($creado->is_active);
        $this->assertSame('Descatalogado 5001', $creado->name);
        $this->assertSame(5101.0, (float) $creado->price_full);
        $this->assertSame(60.0, (float) $creado->cost);
        $this->assertSame('12', $creado->brand);
        $this->assertNull($creado->stock_quantity, 'RF6: sin stock en esta fase');
        $this->assertSame($creado->id, Producto::query()->where('icg_codarticulo', 5001)->value('catalog_product_id'));
        $this->assertNull(Producto::query()->where('icg_codarticulo', 5001)->value('stock_al4_aplicado'));

        // Nada de stock por bodega ni de reflejar AL4.
        $this->assertSame(0, ProductWarehouseStock::query()->count());

        // El equivalente (5004) quedó vinculado al activo y NO se creó otro producto; el sin referencia (5003) tampoco.
        $this->assertSame(1, CatalogProduct::query()->where('reference', 'ACT-1')->count());
        $this->assertNull(Producto::query()->where('icg_codarticulo', 5004)->value('catalog_product_id'), 'H1: el equivalente no guarda catálogo propio');
        $this->assertSame(CatalogProduct::query()->where('reference', 'ACT-1')->value('id'), app(DescatalogadoEquivalenciaService::class)->catalogoResuelto(5004));
        $this->assertNull(Producto::query()->where('icg_codarticulo', 5003)->value('catalog_product_id'));
        $this->assertSame(0, CatalogProduct::query()->where('reference', 'like', 'ICG-%')->count());
        $this->assertSame(3, CatalogProduct::query()->count()); // ACT-1 (activo previo) + REF-A + REF-B
    }

    public function test_ningun_equivalente_queda_con_catalog_product_id(): void
    {
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertGreaterThan(0, Producto::query()->whereNotNull('equivalente_icg_codarticulo')->count());
        $this->assertSame(0, Producto::query()->whereNotNull('equivalente_icg_codarticulo')->whereNotNull('catalog_product_id')->count());
        // Y la resolución en SQL (reutilizable por el mapeo de stock) devuelve el catálogo del activo.
        $fila = app(DescatalogadoEquivalenciaService::class)->consultaConCatalogoResuelto()->where('p.icg_codarticulo', 5004)->firstOrFail();
        $this->assertSame((int) CatalogProduct::query()->where('reference', 'ACT-1')->value('id'), (int) $fila->getAttribute('catalog_resuelto'));
    }

    public function test_el_backfill_de_bodega_central_sigue_tratando_como_activo_al_producto_con_equivalente_descatalogado(): void
    {
        // H1: el activo (catálogo activo con stock) tiene un equivalente descatalogado; el predicado "descatalogado" del backfill
        // (por catalog_product_id) no debe volverlo omitido.
        $cp = $this->catalogo('BF-ACT');
        $cp->forceFill(['stock_quantity' => 7])->save();
        $this->activo(100, 'BF-ACT', $cp);
        $this->fakeExtractor($this->extraccion([[5001, 'bf-act']]));
        $this->correr('legacy:sync-productos-descatalogados');
        $this->stock(5001, 'AL2', 3.0);
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);
        $this->assertSame(100, Producto::query()->where('icg_codarticulo', 5001)->value('equivalente_icg_codarticulo'));

        $out = $this->correr('legacy:backfill-stock-bodega-central');

        $this->assertMatchesRegularExpression('/A SEMBRAR[^|]*\|\s*1\s/', $out);
        $this->assertMatchesRegularExpression('/DESCATALOGADOS en ICG[^|]*\|\s*0 /', $out);
    }

    public function test_el_cuadre_de_stock_no_duplica_la_suma_con_un_equivalente(): void
    {
        $cp = $this->catalogo('CU-ACT');
        $cp->forceFill(['stock_quantity' => 7])->save();
        ProductWarehouseStock::query()->create(['catalog_product_id' => $cp->id, 'warehouse_id' => (int) Warehouse::query()->where('name', 'Bodega Central')->value('id'), 'quantity' => 7]);
        $this->activo(100, 'CU-ACT', $cp);
        $this->fakeExtractor($this->extraccion([[5001, 'cu-act']]));
        $this->correr('legacy:sync-productos-descatalogados');

        $cuadre = app(StockDeltaService::class)->verificarCuadre();

        $this->assertSame(0, $cuadre['no_cuadran'], 'el equivalente no comparte catalog_product_id: la suma no se duplica');
    }

    public function test_apply_deja_auditoria_con_operador_y_ids_creados(): void
    {
        $this->stagearConStock();

        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $evento = AuditLog::query()->where('action', AuditService::LEGACY_CREAR_DESCATALOGADOS_INACTIVOS)->firstOrFail();
        $this->assertSame('tester', $evento->metadata['operador']);
        $this->assertSame(2, $evento->metadata['creados']);
        $this->assertCount(2, $evento->metadata['ids_creados']);
        // La alta de cada catalog_product también queda en audit_logs (LogsAuditTrail).
        $this->assertGreaterThanOrEqual(2, AuditLog::query()->where('action', 'ventas_diseno.catalog_product.created')->count());
    }

    public function test_segunda_corrida_apply_no_crea_ni_vincula_nada(): void
    {
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);
        $total = CatalogProduct::query()->count();

        $out = $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertSame(0, $this->ultimoExit);
        $this->assertSame($total, CatalogProduct::query()->count());
        $this->assertMatchesRegularExpression('/A CREAR como inactivos\s*\|\s*0/', $out);
        $this->assertStringContainsString('0 productos inactivos creados', $out);
    }

    public function test_sin_referencia_no_se_crea_y_se_lista_para_revision_manual(): void
    {
        $this->stagearConStock();

        $out = $this->correr('legacy:crear-descatalogados-inactivos');

        $this->assertStringContainsString('Sin REFPROVEEDOR', $out);
        $this->assertStringContainsString('Descatalogado 5003', $out);
        $this->assertMatchesRegularExpression('/Sin referencia \(revisión manual, NO se crean\)\s*\|\s*1/', $out);
    }

    public function test_referencia_existente_en_catalogo_no_se_duplica_ni_se_pisa(): void
    {
        $this->stagearConStock();
        // Producto de catálogo ajeno (no ligado a staging) con la misma referencia salvo mayúsculas/espacios.
        $ajeno = $this->catalogo(' ref-a ');

        $out = $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertSame(1, CatalogProduct::query()->whereRaw("lower(trim(reference)) = 'ref-a'")->count());
        $this->assertNull(Producto::query()->where('icg_codarticulo', 5001)->value('catalog_product_id'));
        $this->assertTrue($ajeno->refresh()->is_active);
        $this->assertStringContainsString('la referencia ya existe en catalog_products', $out);
        $this->assertSame(1, CatalogProduct::query()->where('reference', 'REF-B')->count()); // el resto sí se crea
    }

    public function test_dos_descatalogados_con_la_misma_referencia_no_duplican_reference(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REP-1'], [5002, 'rep-1']]));
        $this->correr('legacy:sync-productos-descatalogados');
        $this->stock(5001, 'AL2', 1.0);
        $this->stock(5002, 'AL2', 1.0);

        $out = $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertSame(1, CatalogProduct::query()->whereRaw("lower(trim(reference)) = 'rep-1'")->count());
        $this->assertStringContainsString('referencia repetida', $out);
    }

    public function test_sin_precio_publico_se_crea_con_price_full_cero(): void
    {
        $this->fakeExtractor(new ExtractedProductos([new ProductoRow(5001, 'Sin precio', null, null, 'SP-1', true)], [], []));
        $this->correr('legacy:sync-productos-descatalogados');
        $this->stock(5001, 'AL2', 3.0);

        $out = $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $cp = CatalogProduct::query()->where('reference', 'SP-1')->firstOrFail();
        $this->assertFalse($cp->is_active);
        $this->assertSame(0.0, (float) $cp->price_full);
        $this->assertNull($cp->cost);
        $this->assertMatchesRegularExpression('/sin precio público en ICG[^|]*\|\s*1/', $out);
    }

    public function test_descatalogado_sin_stock_en_staging_no_se_crea(): void
    {
        $this->fakeExtractor($this->extraccion([[5001, 'REF-A']]));
        $this->correr('legacy:sync-productos-descatalogados');
        $this->stock(5001, 'AL2', 0.0); // stock cero: no cuenta como "con stock"

        $out = $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->assertSame(0, CatalogProduct::query()->count());
        $this->assertStringContainsString('ninguno con stock', $out);
    }

    // ── Producto inactivo no aparece en el catálogo activo ────────────────────

    public function test_un_producto_creado_inactivo_no_aparece_en_listados_ni_selectores(): void
    {
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);
        $inactivo = CatalogProduct::query()->where('reference', 'REF-A')->firstOrFail();
        $this->assertFalse($inactivo->is_active);

        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));

        $listado = $this->withHeaders($headers)->getJson('/api/ventas-diseno/catalog?search=REF-A&per_page=50');
        $listado->assertOk();
        $this->assertSame([], $listado->json('data'));

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/catalog/{$inactivo->id}")->assertNotFound();

        $selector = $this->withHeaders($headers)->getJson('/api/ventas-diseno/catalog-products?search=REF-A');
        $selector->assertOk();
        $this->assertStringNotContainsString('REF-A', (string) $selector->getContent());
    }

    // ── Mapper: la exclusión normal de descatalogados NO cambia ───────────────

    public function test_el_mapper_por_defecto_sigue_excluyendo_descatalogados_y_la_via_explicita_no(): void
    {
        $p = Producto::query()->create(['icg_codarticulo' => 6001, 'descripcion' => 'D', 'ref_proveedor' => 'MAP-1', 'descatalogado' => true, 'stock_al4' => 9.0]);
        ProductoPrecio::query()->create(['icg_codarticulo' => 6001, 'idtarifav' => 1, 'precio_neto' => 50.0, 'descatalogado_fila' => false]);
        $this->assertTrue($p->descatalogado);

        $porDefecto = (new ProductoMapper())->buildPayloads([6001]);
        $this->assertTrue($porDefecto[0]->isExcluded());
        $this->assertSame('descatalogado en ICG', $porDefecto[0]->excludedReason);

        $explicito = (new ProductoMapper())->buildPayloads([6001], true);
        $this->assertFalse($explicito[0]->isExcluded());
        $this->assertSame(50.0, $explicito[0]->priceFull);
        $this->assertNull($explicito[0]->stockInicial, 'sin stock inicial (RF6)');
    }

    // ── RF5 — verificación ─────────────────────────────────────────────────────

    public function test_verify_resume_y_suma_el_stock_por_almacen(): void
    {
        $this->icgFake(4, ['AL2' => [4, 40.0], 'A1' => [1, 2.5]]);
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);


        $out = $this->correr('legacy:verify-descatalogados');

        $this->assertSame(0, $this->ultimoExit, $out);
        $this->assertMatchesRegularExpression('/Staged[^|]*\|\s*4/', $out);
        $this->assertMatchesRegularExpression('/Con equivalente activo\s*\|\s*1/', $out);
        $this->assertMatchesRegularExpression('/Creados inactivos en catálogo\s*\|\s*2/', $out);
        $this->assertMatchesRegularExpression('/Sin referencia[^|]*\|\s*1/', $out);
        // AL2: 4 artículos × 10 = 40; A1: 1 artículo con 2,5.
        $this->assertMatchesRegularExpression('/AL2\s*\|\s*4\s*\|\s*4\s*\|\s*40\s*\|\s*40\s*\|\s*0\s*\|/', $out);
        $this->assertMatchesRegularExpression('/A1\s*\|\s*1\s*\|\s*1\s*\|\s*2\.5\s*\|\s*2\.5\s*\|\s*0\s*\|/', $out);
    }

    public function test_verify_falla_si_los_esperados_no_coinciden(): void
    {
        $this->stagearConStock();

        $out = $this->correr('legacy:verify-descatalogados', ['--esperados' => 95, '--sin-icg' => true]);

        $this->assertSame(1, $this->ultimoExit);
        $this->assertStringContainsString('DIFERENCIA', $out);
    }

    public function test_verify_falla_si_la_suma_de_stock_por_almacen_difiere_de_icg(): void
    {
        $this->icgFake(4, ['AL2' => [4, 41.0], 'A1' => [1, 2.5]]);
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $out = $this->correr('legacy:verify-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
        $this->assertStringContainsString('almacén AL2', $out);
    }

    public function test_verify_falla_si_un_almacen_de_icg_no_esta_en_staging(): void
    {
        $this->icgFake(4, ['AL2' => [4, 40.0], 'A1' => [1, 2.5], 'ALX' => [1, 5.0]]);
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $this->correr('legacy:verify-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
    }

    public function test_verify_usa_el_conteo_de_icg_como_esperados_si_no_se_indica(): void
    {
        $this->icgFake(95, ['AL2' => [4, 40.0], 'A1' => [1, 2.5]]);
        $this->stagearConStock();
        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $out = $this->correr('legacy:verify-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
        $this->assertStringContainsString('se esperaban 95 artículos y hay 4', $out);
    }

    public function test_verify_falla_si_icg_no_responde(): void
    {
        $this->icgFake(0, [], new RuntimeException('sin conexión'));
        $this->stagearConStock();

        $this->correr('legacy:verify-descatalogados');

        $this->assertSame(1, $this->ultimoExit);
    }

    public function test_verify_senala_equivalentes_sin_catalogo_y_falla(): void
    {
        $this->activo(100, 'SC-1', null); // el activo aún no tiene catálogo
        $this->fakeExtractor($this->extraccion([[5001, 'sc-1']]));
        $this->correr('legacy:sync-productos-descatalogados');
        $this->stock(5001, 'AL2', 1.0);

        $out = $this->correr('legacy:verify-descatalogados', ['--sin-icg' => true]);

        $this->assertSame(1, $this->ultimoExit);
        $this->assertStringContainsString('Equivalentes cuyo activo no tiene catálogo (icg_codarticulo): 5001', $out);
        $this->assertStringContainsString('no resuelven a catalog_products', $out);
    }

    public function test_verify_falla_con_pendientes_sin_resolver_pero_no_por_los_sin_referencia(): void
    {
        $this->stagearConStock();

        $antes = $this->correr('legacy:verify-descatalogados', ['--sin-icg' => true]);
        $this->assertSame(1, $this->ultimoExit, 'hay pendientes de crear (no resuelven)');
        $this->assertStringContainsString('no resuelven a catalog_products', $antes);

        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);
        $this->correr('legacy:verify-descatalogados', ['--sin-icg' => true]);
        $this->assertSame(0, $this->ultimoExit, 'el sin referencia (5003) no cuenta como incumplimiento');
    }

    /** @param array<string, array{0: int, 1: float}> $porAlmacen */
    private function icgFake(int $articulos, array $porAlmacen, ?\Throwable $falla = null): void
    {
        $this->app->instance(DescatalogadoStockIcgExtractorContract::class, new class ($articulos, $porAlmacen, $falla) implements DescatalogadoStockIcgExtractorContract {
            /** @param array<string, array{0: int, 1: float}> $porAlmacen */
            public function __construct(private int $articulos, private array $porAlmacen, private ?\Throwable $falla)
            {
            }

            public function resumen(): array
            {
                if ($this->falla !== null) {
                    throw $this->falla;
                }

                return [
                    'articulos'   => $this->articulos,
                    'por_almacen' => array_map(static fn (array $v): array => ['articulos' => $v[0], 'stock' => $v[1]], $this->porAlmacen),
                ];
            }
        });
    }

    public function test_verify_no_escribe_nada(): void
    {
        $this->stagearConStock();
        $productos = Producto::query()->count();
        $catalogo  = CatalogProduct::query()->count();

        $this->correr('legacy:verify-descatalogados');

        $this->assertSame($productos, Producto::query()->count());
        $this->assertSame($catalogo, CatalogProduct::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::LEGACY_CREAR_DESCATALOGADOS_INACTIVOS)->count());
    }

    // ── Aislamiento de catalog_products compartida ────────────────────────────

    public function test_nunca_toca_productos_de_catalogo_ajenos_al_pipeline(): void
    {
        $ajeno = $this->catalogo('AJENO-1');
        $this->stagearConStock();

        $this->correr('legacy:crear-descatalogados-inactivos', ['--apply' => true, '--operador' => 'tester']);

        $ajeno->refresh();
        $this->assertTrue($ajeno->is_active);
        $this->assertSame('Catálogo AJENO-1', $ajeno->name);
    }
}
