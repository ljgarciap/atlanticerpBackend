<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoFotoHash;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * SCRUM-751 — sube el JPEG a S3 (`Storage::disk('s3-public')`, key
 * `legacy-photos/{tenant}/{codarticulo}.jpg`) y setea `catalog_products.photo_url`
 * (Illumination/migracion-icg-diseno.md §15.4). Arma la URL con el mismo patrón cdn_url/url que
 * `App\Modules\CRM\Services\DocumentService::upload()` — nunca reinventa el armado de URL.
 *
 * CRITERIO CONSERVADOR IDÉNTICO al de `CatalogProductLoader` (SCRUM-750): nunca pisa un
 * `photo_url` que ya tenga valor. Y a diferencia de `CatalogProductLoader` (que solo chequea al
 * armar el worklist), acá se RE-CHEQUEA el valor actual JUSTO ANTES de escribir, dentro de la
 * misma transacción con `lockForUpdate()` — mismo tipo de fix TOCTOU que ya se hizo en Batch 7 de
 * Servicios (`CommissionCaptureService::save()`, ver su docblock): el worklist del comando se
 * arma una vez al arrancar, pero cada `SyncProductoFotoJob` corre en su propio proceso de cola,
 * potencialmente minutos/horas después — una carga manual de `photo_url` (o un segundo Job para
 * el mismo `catalog_product_id`, aunque el worklist no debería producir duplicados) puede colarse
 * en el medio.
 */
final class CatalogPhotoLoader
{
    private const S3_DISK = 's3-public';

    public function __construct(private readonly ProductoFotoConverter $converter)
    {
    }

    /**
     * @return array{
     *   action: 'uploaded'|'reused_dedup'|'skipped_no_photo'|'skipped_already_set'|'skipped_no_catalog_product'|'failed',
     *   reason: string|null,
     * }
     */
    public function load(Producto $producto, string $tenantSlug, ?string $bmpBytes): array
    {
        if ($bmpBytes === null) {
            return ['action' => 'skipped_no_photo', 'reason' => null];
        }

        $catalogProductId = $producto->catalog_product_id;

        if ($catalogProductId === null) {
            return ['action' => 'skipped_no_catalog_product', 'reason' => 'legacy.productos sin catalog_product_id (nunca se cargó a catalog_products)'];
        }

        $hash = hash('sha256', $bmpBytes);

        /** @var ProductoFotoHash|null $existingHash */
        $existingHash = ProductoFotoHash::query()->where('hash', $hash)->first();

        if ($existingHash !== null) {
            return $this->applyPhotoUrl($catalogProductId, $existingHash->s3_key, dedup: true);
        }

        $result = $this->converter->convert($bmpBytes);

        if (! $result->success) {
            return ['action' => 'failed', 'reason' => $result->errorReason];
        }

        $key = self::storageKey($tenantSlug, $producto->icg_codarticulo);

        (new CircuitBreaker(self::S3_DISK))->call(function () use ($key, $result): void {
            /** @var string $jpegBytes */
            $jpegBytes = $result->jpegBytes;
            $uploaded  = Storage::disk(self::S3_DISK)->put($key, $jpegBytes);

            if ($uploaded === false) {
                throw new RuntimeException("S3 upload failed for disk [".self::S3_DISK."] key [{$key}]");
            }
        });

        ProductoFotoHash::query()->updateOrCreate(
            ['hash' => $hash],
            ['s3_key' => $key, 'catalog_product_id' => $catalogProductId],
        );

        return $this->applyPhotoUrl($catalogProductId, $key, dedup: false);
    }

    /**
     * @return array{action: 'uploaded'|'reused_dedup'|'skipped_already_set'|'failed', reason: string|null}
     */
    private function applyPhotoUrl(int $catalogProductId, string $key, bool $dedup): array
    {
        $photoUrl = self::buildPhotoUrl($key);

        return DB::transaction(function () use ($catalogProductId, $photoUrl, $dedup): array {
            /** @var CatalogProduct|null $catalogProduct */
            $catalogProduct = CatalogProduct::query()->whereKey($catalogProductId)->lockForUpdate()->first();

            if ($catalogProduct === null) {
                return ['action' => 'failed', 'reason' => "catalog_products.id={$catalogProductId} ya no existe"];
            }

            // TOCTOU — re-chequeo justo antes de escribir, ver docblock de la clase.
            if ($catalogProduct->photo_url !== null) {
                return ['action' => 'skipped_already_set', 'reason' => null];
            }

            $catalogProduct->photo_url = $photoUrl;
            $catalogProduct->save();

            return ['action' => $dedup ? 'reused_dedup' : 'uploaded', 'reason' => null];
        });
    }

    public static function storageKey(string $tenantSlug, int $codArticulo): string
    {
        return "legacy-photos/{$tenantSlug}/{$codArticulo}.jpg";
    }

    /**
     * Mismo patrón que DocumentService::upload() — CDN_URL (s3-public.cdn_url) si existe, si no
     * AWS_URL, + '/public/' (el root del disco) + la key.
     */
    public static function buildPhotoUrl(string $key): string
    {
        $base = rtrim(
            (string) (config('filesystems.disks.s3-public.cdn_url') ?: config('filesystems.disks.s3-public.url', '')),
            '/',
        );

        return $base !== '' ? "{$base}/public/{$key}" : "/public/{$key}";
    }
}
