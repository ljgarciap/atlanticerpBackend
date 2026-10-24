<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 — pipeline completo del comando `lafayette:sync-productos` (Extractor fakeado: la suite nunca toca la MariaDB
 * externa real; ver `FakeLafayetteProductoExtractor`) sobre la BD REAL de pruebas. El Mapper y el Loader NO se fakean.
 *
 * El comando escribe ÚNICAMENTE en `bodega.lafayette_productos`: ya no crea productos en el catálogo, ni stock en Zona Libre,
 * ni asigna proveedor (cada una de esas ramas se afirma con un test que falla si reaparece).
 */
final class LafayetteSyncProductosCommandTest extends FeatureTestCase
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

    private function sync(?FakeLafayetteProductoExtractor $fake = null, array $options = []): \Illuminate\Testing\PendingCommand
    {
        if ($fake !== null) {
            $this->app->instance(ProductoExtractorContract::class, $fake);
        }

        return $this->artisan('lafayette:sync-productos', ['--tenant' => 'atlantic'] + $options);
    }

    private static function muestra(): ExtractedProductos
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../../Fixtures/Lafayette/vista_productos_lighting_muestra.php';

        return new ExtractedProductos(array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw));
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->sync(new FakeLafayetteProductoExtractor(self::muestra()), ['--dry-run' => true])
            ->expectsOutputToContain('Modo --dry-run')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, LafayetteProducto::query()->count());
        $this->assertSame(0, LafayetteSyncRun::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_SYNC_COMPLETED)->count());
    }

    public function test_corrida_real_carga_la_tabla_registra_la_corrida_y_audita(): void
    {
        $this->sync(new FakeLafayetteProductoExtractor(self::muestra()))->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(19, LafayetteProducto::query()->count());

        $run = LafayetteSyncRun::query()->firstOrFail();
        $this->assertSame(LafayetteSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(20, $run->extraidos);
        $this->assertSame(19, $run->creados);
        $this->assertSame(0, $run->actualizados);
        $this->assertSame(0, $run->sin_cambios);
        $this->assertSame(0, $run->fuera_de_fuente);
        $this->assertSame(0, $run->errores);
        $this->assertSame(0, $run->vinculados);
        $this->assertSame(0, $run->coexistentes);
        $this->assertSame(0, $run->excluidos);

        $audit = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_SYNC_COMPLETED)->firstOrFail();
        $metadata = (array) $audit->metadata;
        $this->assertSame(19, $metadata['insertados']);
        $this->assertSame(20, $metadata['extraidos']);
    }

    public function test_no_toca_el_catalogo_ni_el_stock_ni_asigna_proveedor(): void
    {
        $central = Warehouse::query()->where('name', 'Bodega Central')->firstOrFail();
        $existing = CatalogProduct::query()->create(['reference' => '331819AP2-PAB', 'name' => 'Producto de AtlanticERP con la misma referencia', 'price_full' => 50.0, 'is_active' => true]);
        ProductWarehouseStock::query()->create(['catalog_product_id' => $existing->id, 'warehouse_id' => $central->id, 'quantity' => 4]);
        $antesCatalogo = DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson();
        $antesStock = DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson();

        $this->sync(new FakeLafayetteProductoExtractor(self::muestra()))->assertExitCode(0);
        $this->reactivateTenant();

        // Regresión directa del bug de SCRUM-990: una referencia que coincide ya NO "vincula" ni sobrescribe el producto de AtlanticERP.
        $this->assertSame($antesCatalogo, DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson());
        $this->assertSame($antesStock, DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson());
        $existing->refresh();
        $this->assertSame('Producto de AtlanticERP con la misma referencia', $existing->name);
        $this->assertNull($existing->provider_id);
        $zonaLibre = Warehouse::query()->where('name', 'Bodega Zona Libre')->firstOrFail();
        $this->assertSame(0, ProductWarehouseStock::query()->where('warehouse_id', $zonaLibre->id)->count());
    }

    public function test_re_correr_sin_cambios_no_escribe_y_un_cambio_actualiza_una_fila(): void
    {
        $fake = new FakeLafayetteProductoExtractor(self::muestra());
        $this->sync($fake)->assertExitCode(0);
        $this->reactivateTenant();

        // Mutar la MISMA instancia del fake (ver docblock de FakeLafayetteProductoExtractor::setResult()).
        $fake->setResult(self::muestra());
        $this->sync()->assertExitCode(0);
        $this->reactivateTenant();

        $segunda = LafayetteSyncRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(0, $segunda->creados);
        $this->assertSame(0, $segunda->actualizados);
        $this->assertSame(19, $segunda->sin_cambios);

        $rows = self::muestra()->productos;
        $rows[2] = new LafayetteProductoRow($rows[2]->id, $rows[2]->referencia, $rows[2]->descripcion, $rows[2]->marca, $rows[2]->urlImagen, $rows[2]->categoria, 7, $rows[2]->precio5, $rows[2]->precio6, $rows[2]->cacheUpdatedAt);
        $fake->setResult(new ExtractedProductos($rows));
        $this->sync()->assertExitCode(0);
        $this->reactivateTenant();

        $tercera = LafayetteSyncRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(1, $tercera->actualizados);
        $this->assertSame(18, $tercera->sin_cambios);
        $this->assertSame(7, LafayetteProducto::query()->where('lafayette_id', 'LNP0000003')->value('disponible'));
        $this->assertSame(3, LafayetteSyncRun::query()->count());
    }

    public function test_producto_que_desaparece_de_la_fuente_se_marca_y_se_reporta(): void
    {
        $fake = new FakeLafayetteProductoExtractor(self::muestra());
        $this->sync($fake)->assertExitCode(0);
        $this->reactivateTenant();

        // 19 únicas; vienen 17 (89 %): con el umbral por defecto (0,9) la guarda se activaría, así que el escenario fija el umbral en 0,5 a propósito.
        \Illuminate\Support\Facades\DB::table('atlantic_auth.system_settings')->where('key', 'lafayette_guarda_min_ratio')->update(['value' => \Illuminate\Support\Facades\DB::raw("'0.5'::jsonb")]);
        $fake->setResult(new ExtractedProductos(array_slice(self::muestra()->productos, 2, 17)));
        $this->sync()->assertExitCode(0);
        $this->reactivateTenant();

        $run = LafayetteSyncRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(LafayetteSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertGreaterThanOrEqual(1, $run->fuera_de_fuente);
        $this->assertSame(19, LafayetteProducto::query()->count());
        $this->assertSame($run->fuera_de_fuente, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_guarda_de_extraccion_recortada_registra_partial_y_no_oculta_el_catalogo(): void
    {
        $fake = new FakeLafayetteProductoExtractor(self::muestra());
        $this->sync($fake)->assertExitCode(0);
        $this->reactivateTenant();

        $fake->setResult(new ExtractedProductos(array_slice(self::muestra()->productos, 0, 3)));
        $this->sync()->expectsOutputToContain('GUARDA')->assertExitCode(0);
        $this->reactivateTenant();

        $run = LafayetteSyncRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(LafayetteSyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(0, $run->fuera_de_fuente);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_fallo_del_extractor_registra_corrida_failed_y_no_escribe(): void
    {
        $this->sync(new FakeLafayetteProductoExtractor(null, new RuntimeException('connection refused')))->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, LafayetteProducto::query()->count());
        $run = LafayetteSyncRun::query()->firstOrFail();
        $this->assertSame(LafayetteSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->app->instance(ProductoExtractorContract::class, new FakeLafayetteProductoExtractor(self::muestra()));

        $this->artisan('lafayette:sync-productos', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }
}
