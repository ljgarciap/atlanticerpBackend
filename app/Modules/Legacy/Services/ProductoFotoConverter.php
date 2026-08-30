<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\FotoConversionResult;
use GdImage;
use Throwable;

/**
 * SCRUM-751 — BMP → JPEG + resize + hash (Illumination/migracion-icg-diseno.md §15.3). Sin tocar
 * red ni disco persistente (solo un archivo temporal efímero, ver abajo) — `Services\CatalogPhotoLoader`
 * es quien sube el resultado a S3 y escribe en Postgres.
 *
 * Decodifica con GD (`imagecreatefrombmp`, confirmado disponible en el contenedor `laravel` real,
 * PHP 8.3 — §15.3), redimensiona al lado mayor ≤1600px manteniendo proporción, recodifica a JPEG
 * calidad 82. Libera (`imagedestroy`) cada recurso GD intermedio apenas se usa — con filas de
 * hasta 125MB (§15.1), no hay margen para acumular varias en memoria a la vez.
 *
 * TOLERANCIA A FALLOS (a propósito, mismo principio que `ProductoMapper` de SCRUM-750: nunca
 * todo-o-nada) — un BMP que GD no puede decodificar (header no estándar, archivo truncado, etc.)
 * NUNCA lanza una excepción que aborte el resto de la corrida. `convert()` devuelve un
 * `FotoConversionResult` con `success = false` y el motivo — el llamador (`CatalogPhotoLoader` /
 * `Jobs\SyncProductoFotoJob`) simplemente deja esa fila sin `photo_url`, sin reintentar (un BMP
 * corrupto no se arregla solo en el próximo intento) y sin tumbar el Job de otro artículo.
 *
 * `imagecreatefrombmp()` solo acepta un filename/stream-wrapper, no un string de bytes crudos —
 * se escribe el BMP a un archivo temporal (`tempnam`) en vez de una URI `data://` en memoria:
 * con archivos de hasta 125MB, un `data://` en base64 materializaría ~166MB adicionales en RAM
 * innecesariamente: el archivo temporal es más liviano de memoria a costa de I/O de disco
 * efímero, que es el trade-off correcto para este volumen. El archivo se borra siempre
 * (`finally`), decodifique o no.
 */
final class ProductoFotoConverter
{
    private const MAX_DIMENSION = 1600;
    private const JPEG_QUALITY  = 82;

    public function convert(string $bmpBytes): FotoConversionResult
    {
        $hash = hash('sha256', $bmpBytes);

        // 'BM' (0x42 0x4D) — defensa en profundidad además de la duda documentada en
        // ArticuloFotoExtractor sobre el binary encoding de pdo_sqlsrv: si el header ya no
        // matchea acá, no tiene sentido ni intentar imagecreatefrombmp().
        if ($bmpBytes === '' || ! str_starts_with($bmpBytes, 'BM')) {
            return FotoConversionResult::failure($hash, "Header BMP inválido (no empieza con 'BM')");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'legacy_foto_');
        if ($tmpPath === false) {
            return FotoConversionResult::failure($hash, 'No se pudo crear archivo temporal');
        }

        try {
            if (file_put_contents($tmpPath, $bmpBytes) === false) {
                return FotoConversionResult::failure($hash, 'No se pudo escribir el BMP a disco temporal');
            }

            // imagecreatefrombmp() puede devolver false o emitir un warning (nunca una excepción)
            // ante un BMP con header no estándar — se silencia el warning con @ y se confía en el
            // chequeo explícito de `false`.
            $source = @imagecreatefrombmp($tmpPath);

            if ($source === false) {
                return FotoConversionResult::failure($hash, 'GD no pudo decodificar el BMP (imagecreatefrombmp devolvió false)');
            }

            try {
                return $this->resizeAndEncode($source, $hash);
            } finally {
                imagedestroy($source);
            }
        } catch (Throwable $e) {
            return FotoConversionResult::failure($hash, 'Excepción al procesar el BMP: ' . $e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }

    private function resizeAndEncode(GdImage $source, string $hash): FotoConversionResult
    {
        // imagesx()/imagesy() sobre un GdImage ya decodificado con éxito siempre devuelven un
        // entero positivo (garantía del propio stub de GD) — no hace falta un chequeo defensivo
        // de <= 0 acá.
        $origWidth  = imagesx($source);
        $origHeight = imagesy($source);

        [$targetWidth, $targetHeight] = self::targetDimensions($origWidth, $origHeight);

        $resized     = $source;
        $ownsResized = false;

        if ($targetWidth !== $origWidth || $targetHeight !== $origHeight) {
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($canvas === false) {
                return FotoConversionResult::failure($hash, 'No se pudo crear el lienzo redimensionado');
            }

            $resampled = imagecopyresampled(
                $canvas, $source, 0, 0, 0, 0,
                $targetWidth, $targetHeight, $origWidth, $origHeight,
            );

            if (! $resampled) {
                imagedestroy($canvas);

                return FotoConversionResult::failure($hash, 'imagecopyresampled() falló al redimensionar');
            }

            $resized     = $canvas;
            $ownsResized = true;
        }

        try {
            ob_start();
            $encoded   = imagejpeg($resized, null, self::JPEG_QUALITY);
            $jpegBytes = ob_get_clean();
        } finally {
            if ($ownsResized) {
                imagedestroy($resized);
            }
        }

        if (! $encoded || $jpegBytes === false || $jpegBytes === '') {
            return FotoConversionResult::failure($hash, 'imagejpeg() falló al recodificar a JPEG');
        }

        return FotoConversionResult::success($jpegBytes, $targetWidth, $targetHeight, $hash);
    }

    /**
     * Pura — redimensiona al lado mayor ≤1600px manteniendo proporción. Si ya está dentro del
     * límite, devuelve las dimensiones originales sin cambios (evita un resize innecesario en las
     * fotos que ya son chicas, ver §15.1 — 511 están bajo 50KB).
     *
     * @return array{0: positive-int, 1: positive-int}
     */
    public static function targetDimensions(int $width, int $height): array
    {
        $width  = max(1, $width);
        $height = max(1, $height);

        $maxSide = max($width, $height);

        if ($maxSide <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $scale = self::MAX_DIMENSION / $maxSide;

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }
}
