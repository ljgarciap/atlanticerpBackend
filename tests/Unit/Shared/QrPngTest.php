<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Support\QrPng;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-1156 (T2) — el QR del PDF debe ser exactamente el de `qr_content`. En vez de sumar un decodificador de QR como
 * dependencia, se vuelve a leer el PNG dibujado, módulo por módulo, y se compara con la matriz que calcula la librería.
 * La lectura con un lector real y contra el portal de la DGI de test se hace en Pre-QA.
 */
final class QrPngTest extends TestCase
{
    /** ~480 caracteres, el largo real de `qr_content` en test (URL de consulta de la DGI con CUFE y firma). */
    private static function contenidoReal(): string
    {
        return 'https://dgi-fep-test.mef.gob.pa:40001/Consultas/FacturasPorCUFE?chFE=FE0120000155704849-2-2021-26117960925160960007300000000000000000000'
            .'&iAmb=2&digestValue='.rawurlencode(str_repeat('Zm9vYmFyYmF6cXV4', 14)).'&extra='.str_repeat('abc123', 16);
    }

    protected function setUp(): void
    {
        if (! QrPng::isAvailable()) {
            $this->markTestSkipped('gd no está disponible en este entorno.');
        }
    }

    /** @return list<list<bool>> matriz leída del PNG, sin el borde */
    private static function leerMatriz(string $dataUri, int $modulePx): array
    {
        $png   = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        $image = imagecreatefromstring((string) $png);
        self::assertNotFalse($image);

        $side    = imagesx($image);
        $modules = intdiv($side, $modulePx) - 2 * QrPng::QUIET_ZONE;
        self::assertSame($side, ($modules + 2 * QrPng::QUIET_ZONE) * $modulePx, 'el lado debe ser un número entero de módulos');

        $rows = [];
        for ($y = 0; $y < $modules; $y++) {
            $row = [];
            for ($x = 0; $x < $modules; $x++) {
                // Cada píxel del módulo debe tener el mismo color (sin suavizado); se lee el centro y las cuatro esquinas.
                $colors = [];
                foreach ([[0, 0], [$modulePx - 1, 0], [0, $modulePx - 1], [$modulePx - 1, $modulePx - 1], [intdiv($modulePx, 2), intdiv($modulePx, 2)]] as [$dx, $dy]) {
                    $colors[] = imagecolorat($image, ($x + QrPng::QUIET_ZONE) * $modulePx + $dx, ($y + QrPng::QUIET_ZONE) * $modulePx + $dy) & 0xFFFFFF;
                }
                self::assertCount(1, array_unique($colors), 'módulo con colores mezclados');
                $row[] = $colors[0] === 0x000000;
            }
            $rows[] = $row;
        }
        imagedestroy($image);

        return $rows;
    }

    public function test_el_png_dibujado_coincide_modulo_por_modulo_con_la_matriz_del_contenido(): void
    {
        $contenido = self::contenidoReal();
        $this->assertGreaterThan(450, strlen($contenido));

        $uri = QrPng::dataUri($contenido);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame(QrPng::matrix($contenido), self::leerMatriz($uri, QrPng::MODULE_PX));
    }

    public function test_el_borde_en_blanco_es_de_cuatro_modulos(): void
    {
        $uri   = (string) QrPng::dataUri('https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?chFE=FE01');
        $image = imagecreatefromstring((string) base64_decode(substr($uri, strlen('data:image/png;base64,')), true));
        $this->assertNotFalse($image);

        $side   = imagesx($image);
        $border = QrPng::QUIET_ZONE * QrPng::MODULE_PX;
        for ($i = 0; $i < $side; $i++) {
            for ($d = 0; $d < $border; $d++) {
                $this->assertSame(0xFFFFFF, imagecolorat($image, $i, $d) & 0xFFFFFF, 'borde superior');
                $this->assertSame(0xFFFFFF, imagecolorat($image, $d, $i) & 0xFFFFFF, 'borde izquierdo');
                $this->assertSame(0xFFFFFF, imagecolorat($image, $i, $side - 1 - $d) & 0xFFFFFF, 'borde inferior');
                $this->assertSame(0xFFFFFF, imagecolorat($image, $side - 1 - $d, $i) & 0xFFFFFF, 'borde derecho');
            }
        }
        imagedestroy($image);
    }

    public function test_respeta_los_pixeles_por_modulo_pedidos(): void
    {
        $contenido = 'https://dgi-fep.mef.gob.pa/Consultas/FacturasPorCUFE?chFE=FE01';
        $uri       = (string) QrPng::dataUri($contenido, 7);

        $this->assertSame(QrPng::matrix($contenido), self::leerMatriz($uri, 7));
    }

    public function test_contenido_vacio_o_sin_pixeles_devuelve_null_sin_lanzar(): void
    {
        $this->assertNull(QrPng::dataUri(''));
        $this->assertNull(QrPng::dataUri('https://dgi-fep.mef.gob.pa/x', 0));
    }

    public function test_contenido_que_no_cabe_en_un_qr_devuelve_null_sin_lanzar(): void
    {
        $this->assertNull(QrPng::dataUri(str_repeat('A', 5000)));
    }

    public function test_es_determinista(): void
    {
        $this->assertSame(QrPng::dataUri(self::contenidoReal()), QrPng::dataUri(self::contenidoReal()));
    }
}
