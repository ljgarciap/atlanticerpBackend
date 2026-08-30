<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-799 — pipeline del comando `legacy:fix-productos-referencia`. Igual que
 * `legacy:fuse-clientes`, no toca ICG — opera enteramente sobre `catalog_products` ya cargado, los
 * tests siembran directo.
 */
final class LegacyFixProductosReferenciaCommandTest extends FeatureTestCase
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

    private function migratedProduct(int $codigo, string $reference, ?string $factoryReference): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => $reference, 'factory_reference' => $factoryReference,
            'name' => "Producto {$codigo}", 'price_full' => 100.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => $codigo, 'descripcion' => "Producto {$codigo}", 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);
    }

    public function test_corrida_real_corrige_y_registra_sync_run(): void
    {
        $this->migratedProduct(1001, 'ICG-1001', '42016B-WH');

        $this->artisan('legacy:fix-productos-referencia', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('productos_referencia_swap', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);

        $catalogProduct = CatalogProduct::query()->where('reference', '42016B-WH')->firstOrFail();
        $this->assertNull($catalogProduct->factory_reference);
    }

    public function test_dry_run_no_escribe_nada_ni_registra_sync_run(): void
    {
        $this->migratedProduct(1002, 'ICG-1002', '99-XYZ');

        $this->artisan('legacy:fix-productos-referencia', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
        $catalogProduct = CatalogProduct::query()->where('reference', 'ICG-1002')->firstOrFail();
        $this->assertSame('99-XYZ', $catalogProduct->factory_reference);
    }

    public function test_colision_queda_excluida_y_registrada(): void
    {
        $this->migratedProduct(1003, 'ICG-1003', 'DUP');
        $this->migratedProduct(1004, 'ICG-1004', 'DUP');

        $this->artisan('legacy:fix-productos-referencia', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
    }

    public function test_sin_candidatos_registra_sync_run_en_cero(): void
    {
        $this->artisan('legacy:fix-productos-referencia', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(0, $run->extraidos);
        $this->assertSame(0, $run->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->artisan('legacy:fix-productos-referencia', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }
}
