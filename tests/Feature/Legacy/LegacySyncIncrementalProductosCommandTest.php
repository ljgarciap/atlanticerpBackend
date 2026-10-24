<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-productos`: hash-compare (no reprocesa filas sin cambios
 * reales) + circuit breaker (una corrida con volumen de cambio anormal no aplica sola).
 *
 * GOTCHA de testing (encontrado en esta sesión, no es un bug de producción): `$this->artisan()`
 * reutiliza la MISMA instancia ya resuelta del comando cuando se lo invoca 2+ veces dentro del
 * mismo test — el `ProductoExtractorContract` inyectado por constructor NUNCA se vuelve a resolver
 * del contenedor, así que re-bindear con `$this->app->instance(...)` a mitad de un test (como sí
 * hace, sin problema, el resto de tests de esta suite cuando ambas corridas usan la MISMA data) no
 * tiene efecto si la segunda corrida necesita datos DISTINTOS — el comando sigue viendo el extractor
 * de la primera resolución. Los tests que necesitan datos distintos entre corridas usan
 * `MutableFakeProductoExtractor` (mismo objeto, contenido mutable) en vez de rebindear el
 * contenedor. En producción cada `php artisan legacy:sync-*` es un proceso nuevo — este problema
 * es exclusivo del helper de test `$this->artisan()` dentro de un mismo método.
 */
final class LegacySyncIncrementalProductosCommandTest extends FeatureTestCase
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

    public function test_corrida_real_carga_catalog_products_y_registra_origen_scheduler(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('productos', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);

        $this->assertSame(1, CatalogProduct::query()->count());
    }

    public function test_re_correr_sin_cambios_no_reprocesa_ni_dispara_el_circuit_breaker(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $runs = SyncRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $runs->last()->status);
        $this->assertSame(1, CatalogProduct::query()->count()); // sin duplicar

        // El ahorro real que pide el ticket: sin cambios, el Mapper/CatalogProductLoader ni
        // siquiera corren (mapeados=0, cargados=0) — no solo que el staging no escribe de más.
        $this->assertSame(0, $runs->last()->mapeados);
        $this->assertSame(0, $runs->last()->cargados);
    }

    /**
     * Circuit breaker (SCRUM-838, punto 6): 3 de 4 productos "cambian" en esta corrida (75% > 20%
     * de umbral) — la corrida NO debe llegar a catalog_products, queda held_for_review.
     */
    public function test_circuit_breaker_frena_una_corrida_con_volumen_de_cambio_anormal(): void
    {
        $primeraCorrida = new ExtractedProductos(
            productos: [
                new ProductoRow(1, 'Producto 1', null, null, null, false),
                new ProductoRow(2, 'Producto 2', null, null, null, false),
                new ProductoRow(3, 'Producto 3', null, null, null, false),
                new ProductoRow(4, 'Producto 4', null, null, null, false),
            ],
            precios: [],
            costos: [],
        );
        $fake = new MutableFakeProductoExtractor($primeraCorrida);
        $this->app->instance(ProductoExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Segunda corrida: 3 de los 4 productos cambiaron de descripción (75%, > 20% de umbral).
        $segundaCorrida = new ExtractedProductos(
            productos: [
                new ProductoRow(1, 'Producto 1 (renombrado)', null, null, null, false),
                new ProductoRow(2, 'Producto 2 (renombrado)', null, null, null, false),
                new ProductoRow(3, 'Producto 3 (renombrado)', null, null, null, false),
                new ProductoRow(4, 'Producto 4', null, null, null, false), // sin cambios
            ],
            precios: [],
            costos: [],
        );
        $fake->setResult($segundaCorrida);
        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultimaCorrida = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultimaCorrida->status);
        $this->assertSame(0, $ultimaCorrida->cargados);
        $this->assertSame(0, $ultimaCorrida->mapeados);

        // El staging (legacy.productos) SÍ se actualizó — el circuit breaker frena solo el paso a
        // catalog_products, no el staging (que es de solo referencia, seguro de re-hacer).
        $producto1 = Producto::query()->where('icg_codarticulo', 1)->firstOrFail();
        $this->assertSame('Producto 1 (renombrado)', $producto1->descripcion);

        // catalog_products NUNCA se tocó con los nombres nuevos — el circuit breaker sí frenó esto.
        $this->assertSame(0, CatalogProduct::query()->where('name', 'like', '%renombrado%')->count());
    }

    public function test_circuit_breaker_no_dispara_con_un_cambio_normal(): void
    {
        // Cada producto necesita una tarifa pública (idtarifav=1) o el Mapper lo excluye de
        // catalog_products por su cuenta (ver ProductoMapper) — sin esto, el test no distinguiría
        // "el circuit breaker no frenó nada" de "el Mapper excluyó todo por falta de precio".
        $precios = array_map(static fn (int $i): ProductoPrecioRow => new ProductoPrecioRow($i, 1, 100.0, null, null, false), range(1, 5));

        $primeraCorrida = new ExtractedProductos(
            productos: [
                new ProductoRow(1, 'Producto 1', null, null, null, false),
                new ProductoRow(2, 'Producto 2', null, null, null, false),
                new ProductoRow(3, 'Producto 3', null, null, null, false),
                new ProductoRow(4, 'Producto 4', null, null, null, false),
                new ProductoRow(5, 'Producto 5', null, null, null, false),
            ],
            precios: $precios,
            costos: [],
        );
        $fake = new MutableFakeProductoExtractor($primeraCorrida);
        $this->app->instance(ProductoExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Solo 1 de 5 cambia (20%, no supera el umbral > 20%).
        $segundaCorrida = new ExtractedProductos(
            productos: [
                new ProductoRow(1, 'Producto 1 (renombrado)', null, null, null, false),
                new ProductoRow(2, 'Producto 2', null, null, null, false),
                new ProductoRow(3, 'Producto 3', null, null, null, false),
                new ProductoRow(4, 'Producto 4', null, null, null, false),
                new ProductoRow(5, 'Producto 5', null, null, null, false),
            ],
            precios: [],
            costos: [],
        );
        $fake->setResult($segundaCorrida);
        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultimaCorrida = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultimaCorrida->status);
        $this->assertDatabaseHas('atlantic_ventas_diseno.catalog_products', ['name' => 'Producto 1 (renombrado)']);
    }

    /**
     * Pre-QA 2026-09-14 — la aserción de "sin cambios reales no reprocesa" arriba solo mira los
     * CONTADORES reportados (mapeados=0/cargados=0). Esto va un paso más allá: confirma con `xmin`
     * (columna de sistema de Postgres) que la fila de `legacy.productos` NUNCA fue reescrita por el
     * UPSERT de la segunda corrida — no solo que el Mapper/CatalogProductLoader no corrieron.
     */
    public function test_una_corrida_sin_cambios_reales_no_reescribe_la_fila_en_postgres(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $before = DB::selectOne("SELECT xmin::text as xmin FROM atlantic_legacy.productos WHERE icg_codarticulo = 1001");
        $this->assertNotNull($before);

        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $after = DB::selectOne("SELECT xmin::text as xmin FROM atlantic_legacy.productos WHERE icg_codarticulo = 1001");
        $this->assertSame(
            $before->xmin,
            $after->xmin,
            'La fila no debería haber sido reescrita por Postgres en una corrida sin cambios reales',
        );
    }

    public function test_dry_run_nunca_evalua_el_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-productos', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
        $this->assertSame(0, CatalogProduct::query()->count());
    }

    private static function sampleExtraction(): ExtractedProductos
    {
        return new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false)],
            precios: [new ProductoPrecioRow(1001, 1, 100.0, 119.0, 0.0, false)],
            costos: [],
        );
    }
}
