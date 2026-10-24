<?php

declare(strict_types=1);

namespace App\Shared\Support;

use GdImage;

/**
 * Extraído de `App\Modules\VentasDiseno\Services\CatalogService::uploadPhoto()` (SCRUM-830) para
 * reusarlo tal cual en `QuoteItemPhotoService` (SCRUM-849.6) — mismo criterio de redimensionado
 * con GD (≤maxDimension en el lado mayor, preservando proporción y canal alfa), sin duplicar el
 * algoritmo. Comportamiento sin cambios respecto al original.
 */
trait ResizesImages
{
    /** @return string bytes de la imagen ya redimensionada y recodificada */
    protected function resizeAndEncodeImage(GdImage $source, string $ext, int $maxDimension): string
    {
        $origWidth  = imagesx($source);
        $origHeight = imagesy($source);

        [$targetWidth, $targetHeight] = self::targetImageDimensions($origWidth, $origHeight, $maxDimension);

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new \RuntimeException('No se pudo crear el lienzo para la imagen');
        }

        // Preserva canal alfa (PNG/WEBP con transparencia) — sin esto, el área redimensionada se
        // rellena de negro sólido en vez de quedar transparente.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        $resampled = imagecopyresampled(
            $canvas, $source, 0, 0, 0, 0,
            $targetWidth, $targetHeight, $origWidth, $origHeight,
        );

        if (! $resampled) {
            imagedestroy($canvas);
            throw new \RuntimeException('imagecopyresampled() falló al redimensionar la imagen');
        }

        try {
            ob_start();
            $encoded = match ($ext) {
                'png'   => imagepng($canvas, null, 6),
                'webp'  => imagewebp($canvas, null, 82),
                default => imagejpeg($canvas, null, 85),
            };
            $bytes = ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }

        if (! $encoded || $bytes === false || $bytes === '') {
            throw new \RuntimeException('GD no pudo recodificar la imagen');
        }

        return $bytes;
    }

    /**
     * Pura — redimensiona al lado mayor ≤ $maxDimension manteniendo proporción; si ya está
     * dentro del límite, no cambia nada.
     *
     * @return array{0: positive-int, 1: positive-int}
     */
    private static function targetImageDimensions(int $width, int $height, int $maxDimension): array
    {
        if ($width <= $maxDimension && $height <= $maxDimension) {
            return [max(1, $width), max(1, $height)];
        }

        if ($width >= $height) {
            $targetWidth  = max(1, $maxDimension);
            $targetHeight = max(1, (int) round($height * ($maxDimension / $width)));
        } else {
            $targetHeight = max(1, $maxDimension);
            $targetWidth  = max(1, (int) round($width * ($maxDimension / $height)));
        }

        return [$targetWidth, $targetHeight];
    }
}
