<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SCRUM-745 — sirve la "foto" placeholder de los productos de prueba UAT
 * (5 de 30, RN del ticket) generada al vuelo con GD, sin storage de por
 * medio. Decisión de diseño 2026-08-11: se descartó subir estas fotos a S3
 * (`s3-public`) porque el bucket `atlanticerp-dev` tiene ACLs deshabilitadas
 * ("Bucket owner enforced") y el disco fuerza ACL `public-read` en cada
 * `put()` — cualquier upload a esa zona pública falla ahora mismo con
 * `AccessControlListNotSupported` (afecta también, aparentemente, a
 * `DocumentService::upload()` en la zona pública de Share Links — hallazgo
 * reportado aparte, fuera de alcance de este ticket). Generar la imagen en
 * cada request evita depender de esa infraestructura rota para data que de
 * entrada es sintética.
 *
 * Público, sin auth (mismo patrón que /public/share/{token}), con throttle
 * — no hay nada sensible que exponer (la imagen es 100% determinística a
 * partir de `reference`/`category`, sin lectura de BD), pero sigue siendo
 * un endpoint público sin límite propio de intentos.
 */
class CatalogUatPlaceholderPhotoController
{
    /** RGB de fondo — mismo criterio que CatalogUatTestDataSeeder::CATEGORY_COLORS. */
    private const CATEGORY_COLORS = [
        'candelabros_colgantes' => [91, 165, 160],
        'iluminacion_techo'     => [61, 126, 122],
        'apliques_pared'        => [159, 197, 77],
        'iluminacion_bano'      => [79, 143, 173],
        'iluminacion_exterior'  => [42, 37, 32],
        'lamparas_piso_mesa'    => [200, 140, 60],
        'iluminacion_empotrada' => [120, 90, 160],
        'bombillos'             => [180, 60, 60],
    ];

    public function __invoke(Request $request, string $reference): Response
    {
        $category = $request->query('category', '');
        $category = in_array($category, CatalogProduct::CATEGORIES, true) ? $category : 'candelabros_colgantes';
        $reference = substr($reference, 0, 40);

        [$r, $g, $b] = self::CATEGORY_COLORS[$category];

        $image = imagecreatetruecolor(640, 480);
        $bg    = (int) imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $bg);

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 40, 200, 'PRODUCTO DE PRUEBA UAT', $white);
        imagestring($image, 3, 40, 225, $reference, $white);
        imagestring($image, 2, 40, 250, 'SCRUM-745 - No es una foto real de producto', $white);

        ob_start();
        imagejpeg($image, null, 80);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return response($contents, 200)
            ->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
