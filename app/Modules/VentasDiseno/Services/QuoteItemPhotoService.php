<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\ResizesImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-849.6 — foto de un producto escrito ad-hoc dentro de una cotización (`is_custom=true`,
 * nunca un producto real de Catálogo — ver QuoteItemController::uploadPhoto()). Mismo mecanismo
 * de redimensionado con GD que `CatalogService::uploadPhoto()` (SCRUM-830, ver
 * `App\Shared\Support\ResizesImages`), pero un solo tamaño: acá solo hace falta la miniatura de
 * vista previa (RN del ticket), no una imagen de detalle grande aparte.
 */
final class QuoteItemPhotoService
{
    use ResizesImages;

    private const PHOTO_DISK = 's3-public';

    /** Formatos aceptados — misma whitelist que CatalogService::PHOTO_EXTENSIONS. */
    public const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const PHOTO_MAX_DIMENSION = 300;

    public function uploadPhoto(QuoteItem $item, UploadedFile $file): QuoteItem
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $ext = in_array($ext, self::PHOTO_EXTENSIONS, true) ? $ext : 'jpg';

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('No se pudo leer el archivo de imagen subido');
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            throw new \RuntimeException('GD no pudo decodificar la imagen subida');
        }

        try {
            $bytes = $this->resizeAndEncodeImage($source, $ext, self::PHOTO_MAX_DIMENSION);
        } finally {
            imagedestroy($source);
        }

        $key = 'quotes/' . $item->quote_part_id . '/items/' . $item->id . '/photo/' . Str::uuid()->toString() . ".{$ext}";

        (new CircuitBreaker(self::PHOTO_DISK))->call(function () use ($bytes, $key): void {
            if (Storage::disk(self::PHOTO_DISK)->put($key, $bytes) === false) {
                throw new \RuntimeException("S3 upload failed for photo key [{$key}]");
            }
        });

        // Mismo criterio anti-carrera que CatalogService::uploadPhoto() — releer la key vieja
        // DENTRO de la transacción con lockForUpdate() antes de pisarla.
        DB::transaction(function () use ($item, $key, $file): void {
            $old = QuoteItem::query()->whereKey($item->getKey())->lockForUpdate()->first(['photo_key']);

            $item->update([
                'photo_key'         => $key,
                'photo_filename'    => $file->getClientOriginalName(),
                'photo_uploaded_at' => now(),
            ]);

            if ($old?->photo_key !== null && $old->photo_key !== $key) {
                try {
                    Storage::disk(self::PHOTO_DISK)->delete($old->photo_key);
                } catch (\Throwable $e) {
                    Log::error('quote_item_photo_service.photo_cleanup_failed', [
                        'disk' => self::PHOTO_DISK, 'key' => $old->photo_key, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $item;
    }

    /**
     * Rebote SCRUM-849 (Daniela, 2026-09-14) — funcionaba en dev.atlanticerp.ai, no en test.atlanticerp.ai.
     * Causa raíz verificada en vivo: el archivo SÍ se sube correctamente a S3 en los dos entornos
     * (`Storage::disk('s3-public')->exists()` confirma su existencia), pero este método devolvía
     * la URL directa del bucket (`Storage::disk()->url()`, resuelve a `AWS_URL`) — en dev el
     * bucket permite lectura pública directa, pero en test la policy solo permite `s3:GetObject`
     * a través de CloudFront (`AllowCloudFrontServicePrincipal`), así que la URL directa da 403.
     * Mismo patrón cdn_url/url ya usado en CatalogService::publicPhotoUrl() (y 4 lugares más del
     * código) — este servicio era el único que no lo seguía.
     */
    public function photoUrl(QuoteItem $item): ?string
    {
        if ($item->photo_key === null) {
            return null;
        }

        $base = rtrim((string) (config('filesystems.disks.s3-public.cdn_url') ?: config('filesystems.disks.s3-public.url', '')), '/');

        return $base !== '' ? "{$base}/public/{$item->photo_key}" : $item->photo_key;
    }

    /**
     * SCRUM-849.7 — al duplicar una partida, la foto de cada ítem se copia a una key propia
     * (nunca comparte el archivo con el original): si más adelante alguien reemplaza la foto del
     * ítem original, la limpieza de la key vieja en uploadPhoto() no debe dejar sin imagen a la
     * copia.
     *
     * Rebote de Daniela (2026-09-11, tercera vuelta de SCRUM-849.6) — CRÍTICO real: la foto de
     * TODO ítem duplicado aparecía rota (ícono roto de dompdf en Vista previa/PDF, ícono roto del
     * navegador en el formulario). Root cause verificado en vivo contra dev.atlanticerp.ai: `photo_key`
     * quedaba seteado en BD apuntando a una key que NUNCA se creó en S3. `Storage::disk()->copy()`
     * (Flysystem S3) intenta preservar el ACL/visibility del archivo origen antes de copiar, lo
     * que dispara un `GetObjectAcl` contra S3 — la policy IAM de esta app (bucket con ACLs
     * deshabilitados, "bucket owner enforced") no otorga ese permiso, así que `copy()` fallaba en
     * silencio (la config del disco tiene `throw => false`) y devolvía `false` sin lanzar
     * excepción. `get()` + `put()` no necesitan leer visibility del origen — evitan por completo
     * ese codepath de Flysystem.
     */
    public function copyPhotoTo(QuoteItem $source, QuoteItem $target): void
    {
        if ($source->photo_key === null) {
            return;
        }

        $ext = pathinfo($source->photo_key, PATHINFO_EXTENSION) ?: 'jpg';
        $newKey = 'quotes/' . $target->quote_part_id . '/items/' . $target->id . '/photo/' . Str::uuid()->toString() . ".{$ext}";

        (new CircuitBreaker(self::PHOTO_DISK))->call(function () use ($source, $newKey): void {
            $disk  = Storage::disk(self::PHOTO_DISK);
            $bytes = $disk->get($source->photo_key);
            if ($bytes === null) {
                throw new \RuntimeException("Source photo [{$source->photo_key}] not found while duplicating item photo");
            }
            if ($disk->put($newKey, $bytes) === false) {
                throw new \RuntimeException("S3 upload failed for duplicated photo key [{$newKey}]");
            }
        });

        $target->update([
            'photo_key'         => $newKey,
            'photo_filename'    => $source->photo_filename,
            'photo_uploaded_at' => $source->photo_uploaded_at,
        ]);
    }
}
