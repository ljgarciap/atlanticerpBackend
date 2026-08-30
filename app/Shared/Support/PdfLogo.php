<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * SCRUM-781 (punto 5/8/9) — isólogo de Illuminations embebido como data URI para PDFs generados
 * con dompdf. dompdf en este entorno NO renderiza `<svg>` inline (verificado: un SVG mínimo aislado
 * produce una página en blanco) — el patrón que ya usaba `ventas-diseno/quote-pdf.blade.php` nunca
 * funcionó de verdad, solo estaba en el código sin verificación visual. `<img src="data:...">` sí
 * es un mecanismo probado en este proyecto (mismo usado para `firma_cliente_imagen` en
 * InspectionReportPdfService).
 *
 * Copia propia del repo backend (`resources/images/isologo.png`, mismo archivo que
 * `atlanticerp-frontend/public/logo-icon.png`) — decoupled a propósito del repo frontend, cada uno
 * puede evolucionar su copia sin depender del otro en build/deploy.
 */
final class PdfLogo
{
    private static ?string $cached = null;

    public static function dataUri(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $bytes = file_get_contents(resource_path('images/isologo.png'));

        return self::$cached = 'data:image/png;base64,'.base64_encode($bytes === false ? '' : $bytes);
    }
}
