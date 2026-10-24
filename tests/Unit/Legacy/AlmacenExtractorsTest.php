<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\AlmacenExtractor;
use App\Modules\Legacy\Extractors\AlmacenVerificationExtractor;
use App\Modules\Legacy\Extractors\CostoAlmacenExtractor;
use App\Modules\Legacy\Extractors\IcgTablaExtractor;
use App\Modules\Legacy\Extractors\InventarioCabExtractor;
use App\Modules\Legacy\Extractors\StockAlmacenExtractor;
use App\Modules\Legacy\Extractors\TraspasoCabExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SCRUM-1136 F1 — capa pura (sin DB, sin red) de los extractores de almacenes/stock/costos/traspasos/
 * inventarios de ICG.
 */
final class AlmacenExtractorsTest extends TestCase
{
    public function test_almacen_mapea_todas_las_columnas_tipadas(): void
    {
        $row = AlmacenExtractor::mapRow([
            'CODALMACEN'      => ' AL4 ',
            'NOMBREALMACEN'   => 'Bodega Central',
            'SERIETRASPASOS'  => 'T1',
            'FECHABLOQUEO'    => '2025-01-31 00:00:00',
            'ESMERMAS'        => 0,
            'ESPLANIFICACION' => 1,
            'NOTAS'           => "Línea 1\nLínea 2",
            'DIRECCION'       => 'Calle 50',
            'STOCKMINIMO'     => '2.5',
            'CODCLIENTE'      => '7',
            'VERSION'         => 'binario-ignorado',
        ]);

        $this->assertSame('AL4', $row['codalmacen']);
        $this->assertSame('Bodega Central', $row['nombrealmacen']);
        $this->assertSame('T1', $row['serietraspasos']);
        $this->assertSame('2025-01-31 00:00:00', $row['fechabloqueo']);
        $this->assertFalse($row['esmermas']);
        $this->assertTrue($row['esplanificacion']);
        $this->assertSame("Línea 1\nLínea 2", $row['notas']);
        $this->assertSame('Calle 50', $row['direccion']);
        $this->assertSame(2.5, $row['stockminimo']);
        $this->assertSame(7, $row['codcliente']);
        $this->assertArrayNotHasKey('version', $row);
        $this->assertArrayNotHasKey('extra', $row);
    }

    public function test_almacen_sin_columnas_opcionales_deja_nulos(): void
    {
        $row = AlmacenExtractor::mapRow(['CODALMACEN' => 'ALX']);

        $this->assertSame('ALX', $row['codalmacen']);
        $this->assertNull($row['nombrealmacen']);
        $this->assertNull($row['fechabloqueo']);
        $this->assertNull($row['esmermas']);
    }

    public function test_llave_nula_aborta(): void
    {
        $this->expectException(RuntimeException::class);
        AlmacenExtractor::mapRow(['CODALMACEN' => null]);
    }

    public function test_stock_corregido_trata_negativos_como_cero(): void
    {
        $base = ['CODARTICULO' => '1001', 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4'];

        $neg = StockAlmacenExtractor::mapRow($base + ['STOCK' => '-3.5']);
        $pos = StockAlmacenExtractor::mapRow($base + ['STOCK' => '7.25']);
        $nul = StockAlmacenExtractor::mapRow($base + ['STOCK' => null]);

        $this->assertSame(-3.5, $neg['stock']);
        $this->assertSame(0.0, $neg['stock_corregido']);
        $this->assertSame(7.25, $pos['stock_corregido']);
        $this->assertNull($nul['stock']);
        $this->assertNull($nul['stock_corregido']);
    }

    public function test_stock_mapea_llave_completa_y_columnas_tipadas(): void
    {
        $row = StockAlmacenExtractor::mapRow([
            'CODARTICULO' => 1001, 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'A1',
            'STOCK' => 4, 'FECHAMODIFICADO' => '2026-10-09 10:11:12', 'UBICACION' => '  ',
            'MINIMO' => '1', 'MAXIMO' => '10', 'ENTRANSITO' => '0', 'MERMA' => '0', 'STOCKCONTABLE' => '4',
            'EXTRA_ICG' => 'x',
        ]);

        $this->assertSame(1001, $row['codarticulo']);
        $this->assertSame('.', $row['talla']);
        $this->assertSame('A1', $row['codalmacen']);
        $this->assertSame('2026-10-09 10:11:12', $row['fechamodificado']);
        $this->assertNull($row['ubicacion']);
        $this->assertSame(10.0, $row['maximo']);
        $this->assertSame(4.0, $row['stock']);
        $this->assertSame(4.0, $row['stockcontable']);
    }

    public function test_costo_tipa_todos_los_costos_incluidos_dmn(): void
    {
        $row = CostoAlmacenExtractor::mapRow([
            'CODARTICULO' => '5', 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4',
            'COSTEMEDIO' => 12.5, 'COSTEMEDIODMN' => 12.25, 'ULTIMOCOSTE' => 11, 'CODMONEDA' => 1,
            'FECHAULTCOMPRA' => '2025-03-01 00:00:00', 'VERSION' => 'x',
        ]);

        $this->assertSame(5, $row['codarticulo']);
        $this->assertSame('AL4', $row['codalmacen']);
        $this->assertSame(12.5, $row['costemedio']);
        $this->assertSame(12.25, $row['costemediodmn']);
        $this->assertSame(11.0, $row['ultimocoste']);
        $this->assertSame(1, $row['codmoneda']);
        $this->assertSame('2025-03-01 00:00:00', $row['fechaultcompra']);
        $this->assertNull($row['importecargo1dmn']);
        $this->assertArrayNotHasKey('extra', $row);
    }

    public function test_traspaso_conserva_anulados_y_flags(): void
    {
        $row = TraspasoCabExtractor::mapRow([
            'SERIE' => 'T1', 'CAJA' => 'C1', 'NUMERO' => 77, 'FECHA' => '2024-05-01 08:00:00',
            'ANULADO' => 'T', 'ESAJUSTE' => 'F', 'ESRECUENTO' => 'F', 'ESAUTOMATICO' => 'F', 'RECIBIDO' => 'T',
            'TOTAL' => '150.75', 'OBSERVACIONES' => 'Ajuste anual', 'CODALMACENORIGEN' => 'AL4', 'CODALMACENDESTINO' => 'AL2',
        ]);

        $this->assertSame(77, $row['numero']);
        $this->assertSame(150.75, $row['total']);
        $this->assertSame('Ajuste anual', $row['observaciones']);
        $this->assertSame('AL2', $row['codalmacendestino']);
        $this->assertSame('T', $row['anulado']);
        $this->assertSame('T', $row['recibido']);
    }

    public function test_inventario_usa_fecha_y_almacen_como_llave(): void
    {
        $row = InventarioCabExtractor::mapRow(['FECHA' => '2025-12-31 00:00:00', 'CODALMACEN' => 'AL4', 'ESTADO' => 2, 'ESCIERRE' => 1, 'COMPLETO' => 'T']);

        $this->assertSame('2025-12-31 00:00:00', $row['fecha']);
        $this->assertSame('AL4', $row['codalmacen']);
        $this->assertSame(2, $row['estado']);
        $this->assertTrue($row['escierre']);
        $this->assertSame('T', $row['completo']);
    }

    public function test_columnas_seleccionables_excluyen_binarias_y_version(): void
    {
        $columns = IcgTablaExtractor::selectableColumns([
            ['COLUMN_NAME' => 'CODALMACEN', 'DATA_TYPE' => 'varchar'],
            ['COLUMN_NAME' => 'NOTAS', 'DATA_TYPE' => 'ntext'],
            ['COLUMN_NAME' => 'VERSION', 'DATA_TYPE' => 'timestamp'],
            ['COLUMN_NAME' => 'LOGO', 'DATA_TYPE' => 'varbinary'],
            ['COLUMN_NAME' => 'VERSION2', 'DATA_TYPE' => 'image'],
            (object) ['COLUMN_NAME' => 'OTRA', 'DATA_TYPE' => 'int'],
        ], ['CODALMACEN']);

        $this->assertSame(['CODALMACEN', 'NOTAS', 'OTRA'], $columns);
    }

    public function test_columnas_seleccionables_falla_si_falta_una_llave(): void
    {
        $this->expectException(RuntimeException::class);
        IcgTablaExtractor::selectableColumns([['COLUMN_NAME' => 'NOTAS', 'DATA_TYPE' => 'ntext']], ['CODALMACEN']);
    }

    public function test_page_query_es_solo_select_paginado_y_ordenado(): void
    {
        $sql = IcgTablaExtractor::pageQuery('STOCKS', ['CODARTICULO', 'STOCK'], ['CODARTICULO', 'TALLA']);

        $this->assertSame(
            'SELECT [CODARTICULO], [STOCK] FROM [dbo].[STOCKS] ORDER BY [CODARTICULO], [TALLA] OFFSET ? ROWS FETCH NEXT ? ROWS ONLY',
            $sql,
        );
        $this->assertStringStartsWith('SELECT ', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|MERGE)\b/i', $sql);
    }

    public function test_columns_query_filtra_por_esquema_dbo(): void
    {
        $sql = IcgTablaExtractor::columnsQuery();

        $this->assertStringContainsString("TABLE_SCHEMA = 'dbo'", $sql);
        $this->assertStringContainsString('TABLE_NAME = ?', $sql);
    }

    public function test_llave_nula_informa_tabla_y_llave_parcial(): void
    {
        try {
            StockAlmacenExtractor::mapRow(['CODARTICULO' => 12, 'TALLA' => '.', 'COLOR' => null, 'CODALMACEN' => 'AL4']);
            $this->fail('Debía abortar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tabla STOCKS', $e->getMessage());
            $this->assertStringContainsString('CODARTICULO=12', $e->getMessage());
            $this->assertStringContainsString('CODALMACEN=AL4', $e->getMessage());
        }
    }

    public function test_page_query_rechaza_identificadores_peligrosos(): void
    {
        $this->expectException(RuntimeException::class);
        IcgTablaExtractor::pageQuery('STOCKS', ['A]; DROP TABLE X;--'], ['A']);
    }

    public function test_verificacion_mapea_agregados(): void
    {
        $stock = AlmacenVerificationExtractor::mapStockRows([
            (object) ['CODALMACEN' => ' AL4 ', 'FILAS' => '10', 'STOCK' => '99.5'],
            ['CODALMACEN' => 'A1', 'FILAS' => 2, 'STOCK' => null],
        ]);

        $this->assertSame(['filas' => 10, 'stock' => 99.5], $stock['AL4']);
        $this->assertSame(['filas' => 2, 'stock' => 0.0], $stock['A1']);
        $this->assertSame(11, AlmacenVerificationExtractor::mapCountRow([(object) ['N' => '11']]));
        $this->assertSame(0, AlmacenVerificationExtractor::mapCountRow([]));
        $this->assertStringContainsString('FROM STOCKS', AlmacenVerificationExtractor::stockQuery());
        $this->assertSame('SELECT COUNT(*) AS N FROM TRASPASOSCAB', AlmacenVerificationExtractor::countQuery('traspasos'));
    }
}
