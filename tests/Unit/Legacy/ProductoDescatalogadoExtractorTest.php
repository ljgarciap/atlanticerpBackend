<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\ProductoDescatalogadoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-1136 F5 — las 3 consultas del extractor de descatalogados con stock (sin red): mismo filtro en las tres,
 * y el flujo de activos (`ProductoExtractor`) queda intacto (ver ProductoExtractorTest).
 */
final class ProductoDescatalogadoExtractorTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function consultas(): array
    {
        return [
            'articulos' => [ProductoDescatalogadoExtractor::articulosQuery()],
            'precios'   => [ProductoDescatalogadoExtractor::preciosQuery()],
            'costos'    => [ProductoDescatalogadoExtractor::costosQuery()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('consultas')]
    public function test_las_tres_consultas_filtran_descatalogado_t_con_stock_distinto_de_cero(string $sql): void
    {
        $this->assertStringContainsString("a.DESCATALOGADO = 'T'", $sql);
        $this->assertStringNotContainsString("'F'", $sql);
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM STOCKS sx WHERE sx.CODARTICULO = a.CODARTICULO AND sx.STOCK <> 0)', $sql);
    }

    public function test_articulos_conserva_el_left_join_a_al4_sin_chocar_con_el_alias_del_exists(): void
    {
        $sql = ProductoDescatalogadoExtractor::articulosQuery();

        $this->assertStringContainsString('LEFT JOIN STOCKS s ON', $sql);
        $this->assertStringContainsString("s.CODALMACEN = 'AL4'", $sql);
        $this->assertStringContainsString('STOCK_AL4', $sql);
    }

    public function test_precios_conserva_los_filtros_de_talla_color_y_formato(): void
    {
        $sql = ProductoDescatalogadoExtractor::preciosQuery();

        $this->assertStringContainsString("p.TALLA = '.' AND p.COLOR = '.' AND p.CODFORMATO = 0", $sql);
        $this->assertStringContainsString('INNER JOIN ARTICULOS a', $sql);
    }

    public function test_costos_hace_join_a_articulos_con_el_mismo_filtro(): void
    {
        $this->assertStringContainsString('INNER JOIN ARTICULOS a ON a.CODARTICULO = c.CODARTICULO', ProductoDescatalogadoExtractor::costosQuery());
    }
}
