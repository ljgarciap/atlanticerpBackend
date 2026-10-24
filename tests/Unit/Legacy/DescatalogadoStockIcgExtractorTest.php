<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\DescatalogadoStockIcgExtractor;
use App\Modules\Legacy\Services\DescatalogadoVerificador;
use PHPUnit\Framework\TestCase;

/** SCRUM-1136 F5 (RF5) — consultas y mapeo puros del lado ICG de `legacy:verify-descatalogados` (sin red). */
final class DescatalogadoStockIcgExtractorTest extends TestCase
{
    public function test_las_consultas_son_de_solo_lectura_con_el_filtro_de_descatalogados_con_stock(): void
    {
        foreach ([DescatalogadoStockIcgExtractor::articulosQuery(), DescatalogadoStockIcgExtractor::stockQuery()] as $sql) {
            $this->assertStringStartsWith('SELECT', $sql);
            $this->assertStringContainsString("a.DESCATALOGADO = 'T'", $sql);
            $this->assertStringContainsString('s.STOCK <> 0', $sql);
        }
        $this->assertStringContainsString('GROUP BY', DescatalogadoStockIcgExtractor::stockQuery());
    }

    public function test_mapea_filas_de_stock_y_conteo(): void
    {
        $map = DescatalogadoStockIcgExtractor::mapStockRows([
            (object) ['CODALMACEN' => ' AL2 ', 'ARTICULOS' => '8', 'STOCK' => '28.5'],
            ['codalmacen' => 'A1', 'articulos' => 25, 'stock' => null],
        ]);

        $this->assertSame(['AL2' => ['articulos' => 8, 'stock' => 28.5], 'A1' => ['articulos' => 25, 'stock' => 0.0]], $map);
        $this->assertSame(95, DescatalogadoStockIcgExtractor::mapCountRow([(object) ['N' => '95']]));
        $this->assertSame(0, DescatalogadoStockIcgExtractor::mapCountRow([]));
    }

    public function test_comparar_stock_incluye_almacenes_de_un_solo_lado(): void
    {
        $f = DescatalogadoVerificador::compararStock(
            [['codalmacen' => 'AL2', 'articulos' => 8, 'stock' => 28.0]],
            ['AL2' => ['articulos' => 8, 'stock' => 28.0], 'ALX' => ['articulos' => 1, 'stock' => 3.0]],
        );

        $this->assertSame(['AL2', 'ALX'], array_column($f, 'codalmacen'));
        $this->assertEqualsWithDelta(0.0, $f[0]['diferencia'], 0.0000001);
        $this->assertEqualsWithDelta(-3.0, $f[1]['diferencia'], 0.0000001);
    }
}
