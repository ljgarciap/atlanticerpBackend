<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-750 — pipeline completo del comando `legacy:sync-productos`, con
 * `ProductoExtractorContract` fakeado (sin SQL Server real, ver `FakeProductoExtractor`).
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test
 * deja el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de
 * cualquier assert que dependa de `Model::getTable()` (los `assertDatabaseHas` con tabla
 * calificada a mano no lo necesitan).
 */
final class LegacySyncProductosCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_dry_run_stagea_pero_no_toca_catalog_products_ni_sync_runs(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('illuminations_legacy.productos', ['icg_codarticulo' => 1001]);
        $this->assertSame(0, CatalogProduct::query()->count());
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_carga_catalog_products_y_registra_sync_run(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        // SCRUM-799 (2026-08-27) — `reference` pasa a ser el código real de fabricante
        // (`refProveedor` en `sampleExtraction()`, "55"), no el ID sintético "ICG-1001".
        $this->assertDatabaseHas('illuminations_ventas_diseno.catalog_products', ['reference' => '55', 'factory_reference' => null]);

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('productos', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->errores);

        $producto = Producto::query()->where('icg_codarticulo', 1001)->firstOrFail();
        $this->assertNotNull($producto->catalog_product_id);
    }

    public function test_re_correr_la_corrida_real_es_idempotente(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, CatalogProduct::query()->where('reference', '55')->count());
        $this->assertSame(2, SyncRun::query()->count()); // 2 corridas reales, cada una registrada
    }

    public function test_articulo_sin_precio_publico_se_excluye_y_no_bloquea_el_resto(): void
    {
        $extraction = new ExtractedProductos(
            productos: [
                new ProductoRow(1001, 'Con precio', null, null, null, false),
                new ProductoRow(1002, 'Sin precio', null, null, null, false),
            ],
            precios: [
                new ProductoPrecioRow(1001, 1, 100.0, 119.0, 0.0, false),
                // 1002 sin fila de tarifa 1.
            ],
            costos: [],
        );
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor($extraction));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, CatalogProduct::query()->count());
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            ProductoExtractorContract::class,
            new FakeProductoExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            ProductoExtractorContract::class,
            new FakeProductoExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — hallazgo CRÍTICO de Pre-QA (SCRUM-750, 2026-08-12, ver
     * docs/pre-qa/scrum750-legacy-productos-20260812.md): antes del fix, una excepción durante
     * staging/mapeo/carga (acá forzada con una `descripcion` de 300 chars contra la columna
     * `varchar(255)` de `legacy.productos`) escapaba sin capturar — stack trace crudo Y la corrida
     * NUNCA quedaba registrada en `legacy.sync_runs`. El segundo `try/catch` de
     * `LegacySyncProductosCommand::runPipeline()` (alrededor de `stageMapAndLoad()`) es lo que
     * este test protege — sin él, `assertExitCode(1)` pasaría igual (Laravel captura excepciones
     * no manejadas a nivel de comando) pero `SyncRun::query()->count()` sería 0.
     */
    public function test_falla_de_staging_o_mapeo_registra_sync_run_failed_y_no_deja_stack_trace_crudo(): void
    {
        $extraction = new ExtractedProductos(
            productos: [new ProductoRow(9001, str_repeat('X', 300), null, null, null, false)],
            precios: [],
            costos: [],
        );
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor($extraction));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'illuminations'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
        $this->assertSame(0, CatalogProduct::query()->count());
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeProductoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-productos', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function sampleExtraction(): ExtractedProductos
    {
        return new ExtractedProductos(
            productos: [new ProductoRow(1001, 'Lámpara LED', '3/7', '12', '55', false)],
            precios: [new ProductoPrecioRow(1001, 1, 100.0, 119.0, 0.0, false)],
            costos: [new ProductoCostoRow(1001, 55, 60.0)],
        );
    }
}
