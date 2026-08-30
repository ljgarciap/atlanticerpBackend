<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Services\ProductoFotoConverter;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-751 — BMP fixtures generados en el propio test con GD (`imagebmp()`, confirmado
 * disponible junto con `imagecreatefrombmp()` en el mismo contenedor `laravel` real, PHP 8.3) —
 * sin fixtures binarios embebidos ni SQL Server real.
 */
final class ProductoFotoConverterTest extends TestCase
{
    // ── targetDimensions() — pura ───────────────────────────────────────────────────────────────

    public function test_target_dimensions_no_cambia_si_ya_esta_dentro_del_limite(): void
    {
        [$w, $h] = ProductoFotoConverter::targetDimensions(800, 600);

        $this->assertSame([800, 600], [$w, $h]);
    }

    public function test_target_dimensions_respeta_el_limite_de_1600px_en_el_lado_mayor(): void
    {
        [$w, $h] = ProductoFotoConverter::targetDimensions(2000, 1000);

        $this->assertSame(1600, $w);
        $this->assertSame(800, $h);
    }

    public function test_target_dimensions_mantiene_proporcion_con_alto_mayor(): void
    {
        [$w, $h] = ProductoFotoConverter::targetDimensions(1000, 3200);

        $this->assertSame(500, $w);
        $this->assertSame(1600, $h);
    }

    public function test_target_dimensions_exactamente_en_el_limite_no_cambia(): void
    {
        [$w, $h] = ProductoFotoConverter::targetDimensions(1600, 1600);

        $this->assertSame([1600, 1600], [$w, $h]);
    }

    // ── convert() — con BMPs reales generados por GD ────────────────────────────────────────────

    public function test_convert_bmp_chico_no_lo_redimensiona(): void
    {
        $bmp = self::makeBmpBytes(200, 100);

        $result = (new ProductoFotoConverter())->convert($bmp);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->width);
        $this->assertSame(100, $result->height);
        $this->assertNotNull($result->jpegBytes);
        $this->assertStringStartsNotWith('BM', (string) $result->jpegBytes); // ya es JPEG, no BMP
    }

    public function test_convert_bmp_grande_respeta_el_limite_de_1600px(): void
    {
        $bmp = self::makeBmpBytes(2000, 1000);

        $result = (new ProductoFotoConverter())->convert($bmp);

        $this->assertTrue($result->success);
        $this->assertSame(1600, $result->width);
        $this->assertSame(800, $result->height);
    }

    public function test_convert_calcula_sha256_del_bmp_original_antes_de_decodificar(): void
    {
        $bmp = self::makeBmpBytes(50, 50);

        $result = (new ProductoFotoConverter())->convert($bmp);

        $this->assertSame(hash('sha256', $bmp), $result->hash);
    }

    public function test_convert_es_determinista_para_el_mismo_bmp(): void
    {
        $bmp = self::makeBmpBytes(120, 80);

        $first  = (new ProductoFotoConverter())->convert($bmp);
        $second = (new ProductoFotoConverter())->convert($bmp);

        $this->assertSame($first->hash, $second->hash);
        $this->assertSame($first->success, $second->success);
    }

    // ── convert() — tolerancia a BMP corrupto, nunca lanza ──────────────────────────────────────

    public function test_convert_bmp_con_header_invalido_no_lanza_y_devuelve_fallo(): void
    {
        $garbage = random_bytes(64); // no empieza con 'BM'

        $result = (new ProductoFotoConverter())->convert($garbage);

        $this->assertFalse($result->success);
        $this->assertNull($result->jpegBytes);
        $this->assertNotNull($result->errorReason);
        $this->assertSame(hash('sha256', $garbage), $result->hash); // el hash se calcula igual
    }

    public function test_convert_bmp_truncado_con_header_valido_no_lanza_y_devuelve_fallo(): void
    {
        // Header 'BM' correcto pero el resto del archivo es basura/incompleto — GD debe
        // rechazarlo sin que la excepción escape.
        $corrupt = 'BM' . str_repeat("\x00", 10);

        $result = (new ProductoFotoConverter())->convert($corrupt);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorReason);
    }

    public function test_convert_string_vacio_no_lanza_y_devuelve_fallo(): void
    {
        $result = (new ProductoFotoConverter())->convert('');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorReason);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private static function makeBmpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert($image !== false);

        $color = (int) imagecolorallocate($image, 180, 90, 40);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagebmp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        \assert($bytes !== false);

        return $bytes;
    }
}
