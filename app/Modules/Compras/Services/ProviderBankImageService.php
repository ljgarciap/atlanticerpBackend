<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\Provider;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-819 — respaldo visual de los datos bancarios del proveedor. Mismo mecanismo de
 * resiliencia que PurchaseOrderDocumentService (CircuitBreaker + S3 privado), pero 1:1 con el
 * proveedor (no una tabla de documentos): subir una nueva imagen REEMPLAZA la anterior (RN8) —
 * el archivo viejo se borra de S3 recién después de que el nuevo quedó guardado con éxito, para
 * no perder ninguna de las dos si algo falla a mitad de camino.
 */
final class ProviderBankImageService
{
    private const DISK = 's3-private';
    private const PRESIGNED_URL_TTL_MINUTES = 15;

    /** Storage keys: `<modulo>/<entidad_id>/<uuid>.<ext>` (ver convención en atlanticerp/CLAUDE.md). */
    public function upload(UploadedFile $file, Provider $provider): Provider
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "compras/providers/{$provider->id}/bank/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');
        $previousKey = $provider->bank_image_storage_key;

        $updated = DB::transaction(function () use ($file, $provider, $key): Provider {
            (new CircuitBreaker(self::DISK))->call(function () use ($provider, $file, $key): void {
                $uploaded = Storage::disk(self::DISK)->putFileAs("compras/providers/{$provider->id}/bank", $file, basename($key));

                if ($uploaded === false) {
                    throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
                }
            });

            try {
                $provider->update([
                    'bank_image_storage_key'       => $key,
                    'bank_image_original_filename' => $file->getClientOriginalName(),
                    'bank_image_size_bytes'        => $file->getSize(),
                    'bank_image_mime_type'         => $file->getMimeType() ?? 'application/octet-stream',
                ]);

                return $provider;
            } catch (\Throwable $e) {
                try {
                    Storage::disk(self::DISK)->delete($key);
                } catch (\Throwable $se) {
                    Log::error('provider_bank_image.storage_cleanup_failed', [
                        'key' => $key, 'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }
        });

        // RN8 — reemplazo, no versionado: el archivo viejo ya no está referenciado por nadie
        // desde acá en adelante, se borra recién ahora que el nuevo quedó confirmado en BD.
        if ($previousKey !== null && $previousKey !== $key) {
            $this->deleteFromDisk($previousKey);
        }

        return $updated;
    }

    /** RN7 — eliminar sin reemplazar. */
    public function delete(Provider $provider): Provider
    {
        $key = $provider->bank_image_storage_key;
        if ($key === null) {
            return $provider;
        }

        $provider->update([
            'bank_image_storage_key'       => null,
            'bank_image_original_filename' => null,
            'bank_image_size_bytes'        => null,
            'bank_image_mime_type'         => null,
        ]);

        $this->deleteFromDisk($key);

        return $provider;
    }

    public function presignUrl(Provider $provider): ?string
    {
        if ($provider->bank_image_storage_key === null) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $provider->bank_image_storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL_MINUTES),
        );
    }

    private function deleteFromDisk(string $key): void
    {
        try {
            Storage::disk(self::DISK)->delete($key);
        } catch (\Throwable $e) {
            Log::error('provider_bank_image.storage_delete_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
