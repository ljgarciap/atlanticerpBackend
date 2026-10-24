<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\IcgTablaExtractor;
use App\Modules\Legacy\Extractors\MovimientoExtractor;
use App\Modules\Legacy\Extractors\MovimientoVerificationExtractor;
use App\Modules\Legacy\Services\MovimientoStagingVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SCRUM-1136 F2 — capa pura (sin DB, sin red) del extractor de MOVIMENTS y de su verificación.
 */
final class MovimientoExtractorTest extends TestCase
{
    /** @return array<string, mixed> fila cruda de ICG con las 32 columnas de MOVIMENTS */
    public static function rawRow(int $id = 7, array $overrides = []): array
    {
        return array_replace([
            'ID' => $id, 'CODALMACENORIGEN' => ' AL4 ', 'CODALMACENDESTINO' => 'AL2', 'NUMSERIE' => ' S-1 ',
            'CODARTICULO' => 1234, 'TALLA' => '.', 'COLOR' => ' ', 'PRECIO' => '12.5',
            'FECHA' => '2024-03-05 00:00:00', 'HORA' => '1899-12-30 14:31:09', 'CODPROCLI' => 1001.0, 'TIPO' => ' REG ',
            'UNIDADES' => '-3.5', 'SERIEDOC' => 'A1', 'NUMDOC' => 987654.0, 'SERIECOMPRA' => 'C1', 'NUMFACCOMPRA' => 55,
            'CAJA' => '01', 'STOCK' => 10.25, 'PVP' => 20.0, 'CODMONEDAPVP' => 1, 'CALCMOVPOST' => 'T',
            'UDMEDIDA2' => 2.0, 'ZONA' => 'Z-9', 'PVPDMN' => 19.5, 'PRECIODMN' => 11.5, 'STOCK2' => 4.0,
            'UNID1' => 1.0, 'UNID2' => 2.0, 'UNID3' => 3.0, 'UNID4' => 4.0, 'CODFORMATO' => 3,
        ], $overrides);
    }

    public function test_mapea_todas_las_columnas_tipadas(): void
    {
        $row = MovimientoExtractor::mapRow(self::rawRow());

        $this->assertCount(32, $row, 'ID -> icg_id + 31 columnas = 32 de ICG');
        $this->assertSame(7, $row['icg_id']);
        $this->assertSame('AL4', $row['codalmacenorigen']);
        $this->assertSame('AL2', $row['codalmacendestino']);
        $this->assertSame('S-1', $row['numserie']);
        $this->assertSame(1234, $row['codarticulo']);
        $this->assertSame('.', $row['talla']);
        $this->assertNull($row['color'], 'vacío => null en columnas no llave');
        $this->assertSame(12.5, $row['precio']);
        $this->assertSame('2024-03-05 00:00:00', $row['fecha']);
        $this->assertSame('1899-12-30 14:31:09', $row['hora']);
        $this->assertSame(1001.0, $row['codprocli']);
        $this->assertSame('REG', $row['tipo']);
        $this->assertSame(-3.5, $row['unidades']);
        $this->assertSame('A1', $row['seriedoc']);
        $this->assertSame(987654.0, $row['numdoc'], 'NUMDOC es float en ICG');
        $this->assertSame('C1', $row['seriecompra']);
        $this->assertSame(55, $row['numfaccompra']);
        $this->assertSame('01', $row['caja']);
        $this->assertSame(10.25, $row['stock']);
        $this->assertSame(20.0, $row['pvp']);
        $this->assertSame(1, $row['codmonedapvp']);
        $this->assertSame('T', $row['calcmovpost']);
        $this->assertSame(2.0, $row['udmedida2']);
        $this->assertSame('Z-9', $row['zona']);
        $this->assertSame(19.5, $row['pvpdmn']);
        $this->assertSame(11.5, $row['preciodmn']);
        $this->assertSame(4.0, $row['stock2']);
        $this->assertSame([1.0, 2.0, 3.0, 4.0], [$row['unid1'], $row['unid2'], $row['unid3'], $row['unid4']]);
        $this->assertSame(3, $row['codformato']);
    }

    public function test_columnas_nulas_quedan_nulas(): void
    {
        $row = MovimientoExtractor::mapRow(['ID' => 5]);

        $this->assertSame(5, $row['icg_id']);
        $this->assertNull($row['tipo']);
        $this->assertNull($row['unidades']);
        $this->assertNull($row['fecha']);
    }

    public function test_llave_nula_o_invalida_aborta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Llave natural 'icg_id'");
        MovimientoExtractor::mapRow(self::rawRow(0, ['ID' => null]));
    }

    public function test_llave_no_numerica_aborta(): void
    {
        $this->expectException(RuntimeException::class);
        MovimientoExtractor::mapRow(self::rawRow(0, ['ID' => 'abc']));
    }

    public function test_page_query_es_keyset_con_top_y_un_solo_parametro(): void
    {
        $sql = MovimientoExtractor::pageQuery(['ID', 'TIPO', 'UNIDADES'], 5000);

        $this->assertSame(
            'SELECT TOP (5000) [ID], [TIPO], [UNIDADES] FROM [dbo].[MOVIMENTS] WHERE [ID] > ? ORDER BY [ID]',
            $sql,
        );
        $this->assertStringNotContainsString('OFFSET', $sql);
        $this->assertSame(1, substr_count($sql, '?'));
    }

    public function test_page_query_rechaza_identificadores_peligrosos_y_limites_invalidos(): void
    {
        try {
            MovimientoExtractor::pageQuery(['ID', 'TIPO]; DROP TABLE X;--'], 10);
            $this->fail('Debió rechazar el identificador.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Identificador', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        MovimientoExtractor::pageQuery(['ID'], 0);
    }

    public function test_page_query_sin_columnas_falla(): void
    {
        $this->expectException(RuntimeException::class);
        MovimientoExtractor::pageQuery([], 10);
    }

    public function test_descubrimiento_de_columnas_exige_id_y_excluye_binarias(): void
    {
        $schema = [
            ['COLUMN_NAME' => 'ID', 'DATA_TYPE' => 'int'],
            ['COLUMN_NAME' => 'TIPO', 'DATA_TYPE' => 'nvarchar'],
            ['COLUMN_NAME' => 'VERSION', 'DATA_TYPE' => 'timestamp'],
        ];
        $this->assertSame(['ID', 'TIPO'], IcgTablaExtractor::selectableColumns($schema, ['ID']));

        $this->expectException(RuntimeException::class);
        IcgTablaExtractor::selectableColumns([['COLUMN_NAME' => 'TIPO', 'DATA_TYPE' => 'nvarchar']], ['ID']);
    }

    public function test_verificacion_icg_agrupa_por_tipo_y_deriva_total_y_rango(): void
    {
        $resumen = MovimientoVerificationExtractor::mapRows([
            (object) ['TIPO' => 'REG', 'FILAS' => 3, 'UNIDADES' => 10.5, 'MIN_ID' => 4, 'MAX_ID' => 90],
            (object) ['TIPO' => 'TMP', 'FILAS' => 2, 'UNIDADES' => -1.0, 'MIN_ID' => 1, 'MAX_ID' => 70],
            (object) ['TIPO' => null, 'FILAS' => 1, 'UNIDADES' => null, 'MIN_ID' => 8, 'MAX_ID' => 8],
        ]);

        $this->assertSame(6, $resumen['total']);
        $this->assertSame(1, $resumen['min_id']);
        $this->assertSame(90, $resumen['max_id']);
        $this->assertSame(['' => ['filas' => 1, 'unidades' => 0.0], 'REG' => ['filas' => 3, 'unidades' => 10.5], 'TMP' => ['filas' => 2, 'unidades' => -1.0]], $resumen['por_tipo']);
        $this->assertStringContainsString('FROM MOVIMENTS GROUP BY', MovimientoVerificationExtractor::resumenQuery());
    }

    public function test_compare_ok_y_tolerancia_de_unidades(): void
    {
        $icg     = ['total' => 3, 'min_id' => 1, 'max_id' => 9, 'por_tipo' => ['REG' => ['filas' => 3, 'unidades' => 10.0]]];
        $staging = ['total' => 3, 'min_id' => 1, 'max_id' => 9, 'por_tipo' => ['REG' => ['filas' => 3, 'unidades' => 10.004]]];

        $this->assertTrue(MovimientoStagingVerifier::compare($icg, $staging)['ok']);
    }

    public function test_compare_detecta_diferencias_de_conteo_rango_y_unidades(): void
    {
        $icg = ['total' => 3, 'min_id' => 1, 'max_id' => 9, 'por_tipo' => ['REG' => ['filas' => 2, 'unidades' => 10.0], 'TMP' => ['filas' => 1, 'unidades' => 1.0]]];

        $faltaTipo = ['total' => 2, 'min_id' => 1, 'max_id' => 9, 'por_tipo' => ['REG' => ['filas' => 2, 'unidades' => 10.0]]];
        $r         = MovimientoStagingVerifier::compare($icg, $faltaTipo);
        $this->assertFalse($r['ok']);
        $this->assertCount(2, $r['tipos']);

        $otraSuma = ['total' => 3, 'min_id' => 1, 'max_id' => 9, 'por_tipo' => ['REG' => ['filas' => 2, 'unidades' => 11.0], 'TMP' => ['filas' => 1, 'unidades' => 1.0]]];
        $this->assertFalse(MovimientoStagingVerifier::compare($icg, $otraSuma)['ok']);

        $otroMax = ['total' => 3, 'min_id' => 1, 'max_id' => 8, 'por_tipo' => $icg['por_tipo']];
        $this->assertFalse(MovimientoStagingVerifier::compare($icg, $otroMax)['ok']);
    }
}
