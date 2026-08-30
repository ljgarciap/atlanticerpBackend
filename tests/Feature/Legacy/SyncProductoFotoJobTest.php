<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Jobs\SyncProductoFotoJob;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-751 — `ArticuloFotoExtractorContract` fakeado, sin SQL Server real (mismo patrón que
 * `LegacySyncProductosCommandTest` de SCRUM-750).
 *
 * `SyncProductoFotoJob::handle()` hace `Tenant::forgetCurrent()` en su `finally` (mismo patrón que
 * `LegacySyncProductosCommand::handle()`) — cada `dispatchSync()` de este test deja el tenant
 * "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de cualquier
 * `refresh()`/assert que dependa de `Model::getTable()`.
 */
final class SyncProductoFotoJobTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
        Storage::fake('s3-public');
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_job_completo_extrae_convierte_sube_y_setea_photo_url(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-4001', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 4001, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        $bmp = self::makeBmpBytes(80, 60);
        $this->app->instance(
            ArticuloFotoExtractorContract::class,
            new FakeArticuloFotoExtractor([4001 => $bmp]),
        );

        SyncProductoFotoJob::dispatchSync($producto->id, 'illuminations');
        $this->reactivateTenant();

        $catalogProduct->refresh();
        $this->assertNotNull($catalogProduct->photo_url);
    }

    public function test_job_es_idempotente_si_photo_url_ya_esta_seteado(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-4002', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
            'photo_url' => 'https://cdn.example.com/ya-subida.jpg',
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 4002, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        // El extractor lanza si se llega a invocar — el Job debe salir ANTES, sin tocar SQL Server.
        $this->app->instance(
            ArticuloFotoExtractorContract::class,
            new FakeArticuloFotoExtractor(throws: new RuntimeException('no debería llamarse')),
        );

        SyncProductoFotoJob::dispatchSync($producto->id, 'illuminations');
        $this->reactivateTenant();

        $catalogProduct->refresh();
        $this->assertSame('https://cdn.example.com/ya-subida.jpg', $catalogProduct->photo_url);
    }

    public function test_job_sin_catalog_product_id_no_hace_nada_y_no_lanza(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 4003, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            // catalog_product_id queda null
        ]);

        $this->app->instance(
            ArticuloFotoExtractorContract::class,
            new FakeArticuloFotoExtractor(throws: new RuntimeException('no debería llamarse')),
        );

        SyncProductoFotoJob::dispatchSync($producto->id, 'illuminations');

        $this->addToAssertionCount(1); // llegar hasta acá sin excepción es la aserción
    }

    public function test_job_con_producto_id_inexistente_no_lanza(): void
    {
        $this->app->instance(
            ArticuloFotoExtractorContract::class,
            new FakeArticuloFotoExtractor(throws: new RuntimeException('no debería llamarse')),
        );

        SyncProductoFotoJob::dispatchSync(999999, 'illuminations');

        $this->addToAssertionCount(1);
    }

    public function test_job_con_tenant_inexistente_no_lanza(): void
    {
        SyncProductoFotoJob::dispatchSync(1, 'no-existe');

        $this->addToAssertionCount(1);
    }

    private static function makeBmpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert($image !== false);

        $color = (int) imagecolorallocate($image, 42, 37, 32);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagebmp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        \assert($bytes !== false);

        return $bytes;
    }
}
