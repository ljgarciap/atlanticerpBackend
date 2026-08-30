<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoFotoHash;
use App\Modules\Legacy\Services\CatalogPhotoLoader;
use App\Modules\Legacy\Services\ProductoFotoConverter;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-751 — "Loader-C" (fotos). Storage::fake('s3-public') — sin S3 real, ver docblock de
 * `CatalogPhotoLoader` para el criterio conservador (idéntico a `CatalogProductLoader` de
 * SCRUM-750, más el re-chequeo TOCTOU justo antes de escribir).
 */
final class CatalogPhotoLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Storage::fake('s3-public');
    }

    public function test_sube_la_foto_nueva_y_setea_photo_url(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-2001', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 2001, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        $bmp = self::makeBmpBytes(100, 80);

        $result = (new CatalogPhotoLoader(new ProductoFotoConverter()))
            ->load($producto, 'illuminations', $bmp);

        $this->assertSame('uploaded', $result['action']);

        $catalogProduct->refresh();
        $this->assertNotNull($catalogProduct->photo_url);
        $this->assertSame(
            CatalogPhotoLoader::buildPhotoUrl(CatalogPhotoLoader::storageKey('illuminations', 2001)),
            $catalogProduct->photo_url,
        );

        Storage::disk('s3-public')->assertExists(CatalogPhotoLoader::storageKey('illuminations', 2001));

        $this->assertSame(1, ProductoFotoHash::query()->where('hash', hash('sha256', $bmp))->count());
    }

    public function test_articulo_sin_foto_no_sube_nada(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-2002', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 2002, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        $result = (new CatalogPhotoLoader(new ProductoFotoConverter()))
            ->load($producto, 'illuminations', null);

        $this->assertSame('skipped_no_photo', $result['action']);
        $catalogProduct->refresh();
        $this->assertNull($catalogProduct->photo_url);
    }

    public function test_nunca_pisa_un_photo_url_ya_seteado(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-2003', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
            'photo_url' => 'https://cdn.example.com/ya-existente.jpg',
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 2003, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        $bmp = self::makeBmpBytes(100, 80);

        $result = (new CatalogPhotoLoader(new ProductoFotoConverter()))
            ->load($producto, 'illuminations', $bmp);

        $this->assertSame('skipped_already_set', $result['action']);

        $catalogProduct->refresh();
        $this->assertSame('https://cdn.example.com/ya-existente.jpg', $catalogProduct->photo_url);

        // El upload a S3 SÍ ocurre antes del re-chequeo (ver docblock — el TOCTOU se cierra recién
        // al escribir en Postgres), pero photo_url nunca cambia.
    }

    public function test_bmp_corrupto_no_sube_nada_y_no_lanza(): void
    {
        $catalogProduct = CatalogProduct::query()->create([
            'reference' => 'ICG-2004', 'name' => 'Lámpara', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $producto = Producto::query()->create([
            'icg_codarticulo' => 2004, 'descripcion' => 'Lámpara', 'descatalogado' => false,
            'catalog_product_id' => $catalogProduct->id,
        ]);

        $result = (new CatalogPhotoLoader(new ProductoFotoConverter()))
            ->load($producto, 'illuminations', random_bytes(32));

        $this->assertSame('failed', $result['action']);
        $this->assertNotNull($result['reason']);

        $catalogProduct->refresh();
        $this->assertNull($catalogProduct->photo_url);
    }

    public function test_dedup_real_entre_2_filas_con_el_mismo_hash_reusa_el_s3_key_sin_re_subir(): void
    {
        $catalogA = CatalogProduct::query()->create([
            'reference' => 'ICG-3001', 'name' => 'Lámpara A', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $productoA = Producto::query()->create([
            'icg_codarticulo' => 3001, 'descripcion' => 'Lámpara A', 'descatalogado' => false,
            'catalog_product_id' => $catalogA->id,
        ]);

        $catalogB = CatalogProduct::query()->create([
            'reference' => 'ICG-3002', 'name' => 'Lámpara B (misma foto placeholder)', 'price_full' => 100.0, 'is_active' => true,
        ]);
        $productoB = Producto::query()->create([
            'icg_codarticulo' => 3002, 'descripcion' => 'Lámpara B', 'descatalogado' => false,
            'catalog_product_id' => $catalogB->id,
        ]);

        $bmp = self::makeBmpBytes(60, 60); // mismos bytes exactos para las dos filas

        $loader = new CatalogPhotoLoader(new ProductoFotoConverter());

        $resultA = $loader->load($productoA, 'illuminations', $bmp);
        $resultB = $loader->load($productoB, 'illuminations', $bmp);

        $this->assertSame('uploaded', $resultA['action']);
        $this->assertSame('reused_dedup', $resultB['action']);

        $catalogA->refresh();
        $catalogB->refresh();

        // Mismo s3_key para ambos — reusa la foto de A, nunca sube un segundo objeto para B.
        $this->assertSame($catalogA->photo_url, $catalogB->photo_url);

        Storage::disk('s3-public')->assertExists(CatalogPhotoLoader::storageKey('illuminations', 3001));
        Storage::disk('s3-public')->assertMissing(CatalogPhotoLoader::storageKey('illuminations', 3002));

        $this->assertSame(1, ProductoFotoHash::query()->where('hash', hash('sha256', $bmp))->count());
    }

    private static function makeBmpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert($image !== false);

        $color = (int) imagecolorallocate($image, 90, 165, 160);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagebmp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        \assert($bytes !== false);

        return $bytes;
    }
}
