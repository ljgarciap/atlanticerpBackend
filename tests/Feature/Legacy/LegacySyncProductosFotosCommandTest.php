<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Jobs\SyncProductoFotoJob;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-751 — pipeline completo del comando `legacy:sync-productos-fotos`, con
 * `ArticuloFotoExtractorContract` fakeado (sin SQL Server real) y `Queue::fake()` (nunca corre
 * Jobs reales — este comando solo los despacha, ver docblock de la clase).
 *
 * Mismo gotcha de `Tenant::forgetCurrent()` que `LegacySyncProductosCommandTest` de SCRUM-750 —
 * `reactivateTenant()` después de cada `artisan(...)`.
 */
final class LegacySyncProductosFotosCommandTest extends FeatureTestCase
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

    public function test_worklist_solo_incluye_productos_con_catalog_product_id_y_sin_photo_url(): void
    {
        Queue::fake();
        $this->app->instance(ArticuloFotoExtractorContract::class, new FakeArticuloFotoExtractor());

        // Candidato real — catalog_product_id seteado, photo_url null.
        $catalogA = CatalogProduct::query()->create([
            'reference' => 'ICG-5001', 'name' => 'A', 'price_full' => 1.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 5001, 'descripcion' => 'A', 'descatalogado' => false,
            'catalog_product_id' => $catalogA->id,
        ]);

        // Ya tiene foto — no debería entrar al worklist.
        $catalogB = CatalogProduct::query()->create([
            'reference' => 'ICG-5002', 'name' => 'B', 'price_full' => 1.0, 'is_active' => true,
            'photo_url' => 'https://cdn.example.com/ya-tiene.jpg',
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 5002, 'descripcion' => 'B', 'descatalogado' => false,
            'catalog_product_id' => $catalogB->id,
        ]);

        // Nunca se cargó a catalog_products (catalog_product_id null) — no debería entrar.
        Producto::query()->create([
            'icg_codarticulo' => 5003, 'descripcion' => 'C', 'descatalogado' => false,
        ]);

        $this->artisan('legacy:sync-productos-fotos', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        Queue::assertPushed(SyncProductoFotoJob::class, 1);

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('productos_fotos', $run->entidad);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_limit_corta_el_worklist(): void
    {
        Queue::fake();
        $this->app->instance(ArticuloFotoExtractorContract::class, new FakeArticuloFotoExtractor());

        for ($i = 1; $i <= 5; $i++) {
            $catalog = CatalogProduct::query()->create([
                'reference' => "ICG-60{$i}", 'name' => "P{$i}", 'price_full' => 1.0, 'is_active' => true,
            ]);
            Producto::query()->create([
                'icg_codarticulo' => 6000 + $i, 'descripcion' => "P{$i}", 'descatalogado' => false,
                'catalog_product_id' => $catalog->id,
            ]);
        }

        $this->artisan('legacy:sync-productos-fotos', ['--tenant' => 'atlantic', '--limit' => 2])
            ->assertExitCode(0);
        $this->reactivateTenant();

        Queue::assertPushed(SyncProductoFotoJob::class, 2);

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(2, $run->extraidos);
    }

    public function test_dry_run_nunca_despacha_jobs_ni_registra_sync_run(): void
    {
        Queue::fake();

        $catalog = CatalogProduct::query()->create([
            'reference' => 'ICG-7001', 'name' => 'A', 'price_full' => 1.0, 'is_active' => true,
        ]);
        Producto::query()->create([
            'icg_codarticulo' => 7001, 'descripcion' => 'A', 'descatalogado' => false,
            'catalog_product_id' => $catalog->id,
        ]);

        $bmp = self::makeBmpBytes(100, 50);
        $this->app->instance(ArticuloFotoExtractorContract::class, new FakeArticuloFotoExtractor([7001 => $bmp]));

        $this->artisan('legacy:sync-productos-fotos', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncRun::query()->count());

        $catalog->refresh();
        $this->assertNull($catalog->photo_url); // dry-run nunca escribe Postgres
    }

    public function test_worklist_vacio_no_despacha_nada_pero_registra_la_corrida(): void
    {
        Queue::fake();
        $this->app->instance(ArticuloFotoExtractorContract::class, new FakeArticuloFotoExtractor());

        $this->artisan('legacy:sync-productos-fotos', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        Queue::assertNothingPushed();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(0, $run->extraidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        Queue::fake();

        $this->artisan('legacy:sync-productos-fotos', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    private static function makeBmpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert($image !== false);

        $color = (int) imagecolorallocate($image, 159, 197, 77);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagebmp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        \assert($bytes !== false);

        return $bytes;
    }
}
