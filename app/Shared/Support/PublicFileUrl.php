<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * URL pública de un archivo de la zona pública (`s3-public`). Con S3 es `CDN_URL` o `AWS_URL` + `/public/` + clave (el disco lleva ese prefijo
 * como raíz); con el almacenamiento local de desarrollo (`STORAGE_DRIVER=local`) es la ruta que sirve la propia app.
 */
final class PublicFileUrl
{
    public static function for(string $key): ?string
    {
        /** @var array<string, mixed> $disk */
        $disk = (array) config('filesystems.disks.s3-public', []);

        if (($disk['driver'] ?? null) === 'local') {
            $base = rtrim((string) ($disk['url'] ?? ''), '/');

            return $base !== '' ? $base.'/'.ltrim($key, '/') : null;
        }

        $base = rtrim((string) (($disk['cdn_url'] ?? null) ?: ($disk['url'] ?? '')), '/');

        return $base !== '' ? "{$base}/public/{$key}" : null;
    }
}
