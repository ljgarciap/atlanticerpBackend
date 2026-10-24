<?php

declare(strict_types=1);

namespace App\Shared\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Throwable;

/**
 * SCRUM-1156 — código QR como PNG en data URI, para incrustarlo en un PDF de dompdf (que no renderiza `<svg>` inline; el
 * logo ya viaja igual, ver `PdfLogo::dataUri()`). La matriz la calcula `bacon/bacon-qr-code` y se dibuja con GD en módulos
 * de píxeles ENTEROS (sin suavizado: un QR borroso no se lee al imprimir) más el borde en blanco de 4 módulos que exige la
 * norma. No usa `imagick` (el contenedor solo tiene `gd`, que el Dockerfile instala siempre).
 *
 * Nunca lanza: si falta `gd` o el contenido no cabe en un QR devuelve null, y quien llama (`InvoicePdfVariant`) cae al
 * formato «Sin validez fiscal» en vez de romper el PDF con un 500.
 */
final class QrPng
{
    /** Borde en blanco, en módulos (mínimo de la norma ISO/IEC 18004). */
    public const QUIET_ZONE = 4;

    /** Píxeles por módulo del PNG: ~680 px para un QR de ~480 caracteres; más resolución que el tamaño en el PDF para que no se difumine al imprimir. */
    public const MODULE_PX = 6;

    /** Lado, en píxeles de CSS (dompdf), con que el PDF muestra el QR (~5.3 cm). Un QR de ~480 caracteres necesita módulos de ≥ 0.4 mm para escanearse con un celular. */
    public const DISPLAY_PX = 200;

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagepng');
    }

    public static function dataUri(string $content, int $modulePx = self::MODULE_PX): ?string
    {
        if (! self::isAvailable() || $content === '' || $modulePx < 1) {
            return null;
        }

        try {
            $matrix = self::matrix($content);
            $count  = count($matrix);
            $side   = ($count + 2 * self::QUIET_ZONE) * $modulePx;

            $image = imagecreatetruecolor($side, $side);
            if ($image === false) {
                return null;
            }

            $white = (int) imagecolorallocate($image, 255, 255, 255);
            $black = (int) imagecolorallocate($image, 0, 0, 0);
            imagefilledrectangle($image, 0, 0, $side - 1, $side - 1, $white);

            foreach ($matrix as $y => $row) {
                foreach ($row as $x => $dark) {
                    if (! $dark) {
                        continue;
                    }
                    $px = ($x + self::QUIET_ZONE) * $modulePx;
                    $py = ($y + self::QUIET_ZONE) * $modulePx;
                    imagefilledrectangle($image, $px, $py, $px + $modulePx - 1, $py + $modulePx - 1, $black);
                }
            }

            $nivel = ob_get_level();
            ob_start();
            try {
                imagepng($image);
            } finally {
                $png = (string) ob_get_clean();
                while (ob_get_level() > $nivel) {
                    ob_end_clean();
                }
                imagedestroy($image);
            }

            return $png === '' ? null : 'data:image/png;base64,'.base64_encode($png);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Matriz de módulos del QR (true = oscuro), sin el borde. Nivel de corrección M. Pública para que las pruebas
     * comparen el PNG dibujado contra ella.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $content): array
    {
        $byteMatrix = Encoder::encode($content, ErrorCorrectionLevel::M(), prefixEci: false)->getMatrix();

        $rows = [];
        for ($y = 0; $y < $byteMatrix->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $byteMatrix->getWidth(); $x++) {
                $row[] = $byteMatrix->get($x, $y) === 1;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
