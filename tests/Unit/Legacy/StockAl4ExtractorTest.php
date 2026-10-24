<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\StockAl4Extractor;
use PHPUnit\Framework\TestCase;

/** SCRUM-1114 — consulta y mapeo puros del extractor de stock de AL4 (sin conexión a ICG). */
final class StockAl4ExtractorTest extends TestCase
{
    public function test_la_consulta_lee_solo_stocks_de_al4_sin_variantes(): void
    {
        $sql = StockAl4Extractor::stockQuery();

        $this->assertStringContainsString("FROM STOCKS", $sql);
        $this->assertStringContainsString("CODALMACEN = 'AL4'", $sql);
        $this->assertStringContainsString("TALLA = '.'", $sql);
        $this->assertStringContainsString("COLOR = '.'", $sql);
        $this->assertStringNotContainsString('ARTICULOS', $sql);
    }

    public function test_mapea_filas_a_codarticulo_stock_con_negativos_y_nulos(): void
    {
        $map = StockAl4Extractor::mapRows([
            (object) ['CODARTICULO' => '101', 'STOCK' => '12.50'],
            ['CODARTICULO' => 102, 'STOCK' => -7],
            ['CODARTICULO' => 103, 'STOCK' => null],
            ['CODARTICULO' => 'x', 'STOCK' => 5],
            ['STOCK' => 5],
        ]);

        $this->assertSame([101 => 12.5, 102 => -7.0, 103 => 0.0], $map);
    }
}
