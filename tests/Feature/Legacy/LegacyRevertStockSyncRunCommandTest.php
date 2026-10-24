<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1114 — reversa de una corrida de stock por su id (criterio 12). */
final class LegacyRevertStockSyncRunCommandTest extends FeatureTestCase
{
    private int $cod = 850000;
    private ?FakeStockAl4Extractor $fake = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        StockSyncSettings::current()->update([
            'activo' => false, 'baseline_inicializada_at' => now(), 'max_productos_con_delta_pct' => 100,
            'max_unidades_abs_por_producto' => 1_000_000, 'max_unidades_abs_total' => 10_000_000,
        ]);
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{0: int, 1: int} */
    private function producto(int $stock): array
    {
        $this->tenant();
        $cat = CatalogProduct::create(['reference' => 'RV-' . ++$this->cod, 'name' => 'P' . $this->cod, 'price_full' => 1.0, 'is_active' => true, 'stock_quantity' => $stock]);
        Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG', 'descatalogado' => false, 'catalog_product_id' => $cat->id, 'stock_al4_aplicado' => $stock]);
        ProductWarehouseStock::create(['catalog_product_id' => $cat->id, 'warehouse_id' => (int) Warehouse::query()->where('name', 'Bodega Central')->value('id'), 'quantity' => $stock]);

        return [(int) $cat->id, $this->cod];
    }

    /** @param array<int, float> $al4 */
    private function sync(array $al4): int
    {
        if ($this->fake === null) {
            $this->fake = new FakeStockAl4Extractor($al4);
            $this->app->instance(StockAl4ExtractorContract::class, $this->fake);
        } else {
            $this->fake->poner($al4);
        }
        $this->artisan('legacy:sync-incremental-stock', ['--tenant' => 'atlantic', '--operador' => 'Prueba'])->assertExitCode(0);
        $this->tenant();

        return (int) SyncRun::query()->where('entidad', 'stock')->orderByDesc('id')->value('id');
    }

    private function stock(int $cat): int
    {
        $this->tenant();

        return (int) CatalogProduct::query()->whereKey($cat)->value('stock_quantity');
    }

    private function base(int $cod): int
    {
        $this->tenant();

        return (int) Producto::query()->where('icg_codarticulo', $cod)->value('stock_al4_aplicado');
    }

    /** @param array<string, mixed> $o */
    private function revertir(int $run, array $o = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('legacy:revert-stock-sync-run', ['run' => $run, '--tenant' => 'atlantic'] + $o);
    }

    public function test_dry_run_no_registra_nada(): void
    {
        [$cat, $cod] = $this->producto(100);
        $run = $this->sync([$cod => 90.0]);

        $this->revertir($run)->assertExitCode(0);

        $this->assertSame(90, $this->stock($cat));
        $this->assertSame(90, $this->base($cod));
    }

    public function test_apply_devuelve_el_stock_y_la_base_y_deja_contramovimiento(): void
    {
        [$cat, $cod] = $this->producto(100);
        $run = $this->sync([$cod => 90.0]);

        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(100, $this->base($cod));
        $this->tenant();
        $contra = InventoryMovement::query()->where('catalog_product_id', $cat)->where('referencia_entidad_tipo', 'legacy.sync_run_reversa')->firstOrFail();
        $this->assertSame(10, (int) $contra->cantidad);
        $this->assertArrayHasKey('revertida', SyncRun::query()->findOrFail($run)->resumen);
    }

    public function test_una_corrida_no_se_revierte_dos_veces(): void
    {
        [, $cod] = $this->producto(100);
        $run = $this->sync([$cod => 90.0]);

        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);
        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(1);
    }

    public function test_no_revierte_un_producto_con_una_corrida_posterior(): void
    {
        [$cat, $cod] = $this->producto(100);
        $primera = $this->sync([$cod => 90.0]);
        $this->sync([$cod => 70.0]);

        $this->revertir($primera, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame(70, $this->stock($cat)); // intacto: se reportó para decisión manual
        $this->assertSame(70, $this->base($cod));
        // Quedó producto sin revertir: la corrida NO se marca revertida, solo con avance parcial.
        $this->tenant();
        $resumen = SyncRun::query()->findOrFail($primera)->resumen;
        $this->assertArrayNotHasKey('revertida', $resumen);
        $this->assertArrayHasKey('reversa_parcial', $resumen);
    }

    public function test_apply_exige_operador_y_una_corrida_inexistente_falla(): void
    {
        [, $cod] = $this->producto(100);
        $run = $this->sync([$cod => 90.0]);

        $this->revertir($run, ['--apply' => true])->assertExitCode(1);
        $this->revertir(999999)->assertExitCode(1);
    }

    public function test_repetir_apply_tras_una_caida_a_mitad_no_revierte_dos_veces_lo_ya_revertido(): void
    {
        [$a, $codA] = $this->producto(100);
        [$b, $codB] = $this->producto(100);
        $run = $this->sync([$codA => 90.0, $codB => 80.0]);

        // Simula una reversa que se cayó después de revertir solo el producto A: su contramovimiento ya existe.
        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);
        $this->tenant();
        SyncRun::query()->findOrFail($run)->update(['resumen' => ['con_delta' => 2]]); // vuelve a "no revertida"
        $stockA = $this->stock($a);
        $stockB = $this->stock($b);

        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame($stockA, $this->stock($a)); // 100: no se revirtió otra vez (no pasó a 110)
        $this->assertSame($stockB, $this->stock($b));
        $this->assertSame(100, $this->stock($a));
        $this->assertSame(100, $this->base($codA));
    }

    public function test_se_niega_a_revertir_con_el_sync_encendido(): void
    {
        [$cat, $cod] = $this->producto(100);
        $run = $this->sync([$cod => 90.0]);
        $this->tenant();
        StockSyncSettings::current()->update(['activo' => true]);

        $this->revertir($run, ['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(1);

        $this->assertSame(90, $this->stock($cat));
    }
}
