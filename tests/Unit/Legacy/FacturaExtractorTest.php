<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\FacturaExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-760 — tests puros de la capa de mapeo de `FacturaExtractor` (sin DB, sin conexión de red).
 * Mismo patrón que `AlbaranExtractorTest`.
 */
final class FacturaExtractorTest extends TestCase
{
    public function test_map_factura_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::facturaRow();

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertSame('A', $factura->icgNumserie);
        $this->assertSame(9001, $factura->icgNumfactura);
        $this->assertSame('B', $factura->icgN);
        $this->assertSame(555, $factura->icgCodcliente);
        $this->assertSame(77, $factura->icgCodvendedor);
        $this->assertSame(1500.50, $factura->totalNeto);
        $this->assertSame(105.03, $factura->totalImpuestos);
        $this->assertSame(1605.53, $factura->totalBruto);
        $this->assertSame('REF-9001', $factura->sufactura);
    }

    public function test_map_factura_row_icg_n_es_string_no_int(): void
    {
        $row = self::facturaRow(['N' => 'B']);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertIsString($factura->icgN);
        $this->assertSame('B', $factura->icgN);
    }

    public function test_map_factura_row_codcliente_nulo_no_revienta(): void
    {
        $row = self::facturaRow(['CODCLIENTE' => null]);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNull($factura->icgCodcliente);
    }

    public function test_map_factura_row_codvendedor_nulo(): void
    {
        $row = self::facturaRow(['CODVENDEDOR' => null]);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNull($factura->icgCodvendedor);
    }

    public function test_map_factura_row_fecha_valida_se_parsea(): void
    {
        $row = self::facturaRow(['FECHA' => '2026-01-15 10:30:00']);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNotNull($factura->fecha);
        $this->assertSame('2026-01-15', $factura->fecha->format('Y-m-d'));
    }

    public function test_map_factura_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::facturaRow(['FECHA' => 'no-es-una-fecha']);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNull($factura->fecha);
        $this->assertSame(555, $factura->icgCodcliente);
    }

    public function test_map_factura_row_sufactura_string_vacio_se_normaliza_a_null(): void
    {
        $row = self::facturaRow(['SUFACTURA' => '']);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNull($factura->sufactura);
    }

    public function test_map_factura_row_totales_nulos(): void
    {
        $row = self::facturaRow(['TOTALNETO' => null, 'TOTALIMPUESTOS' => null, 'TOTALBRUTO' => null]);

        $factura = FacturaExtractor::mapFacturaRow($row);

        $this->assertNull($factura->totalNeto);
        $this->assertNull($factura->totalImpuestos);
        $this->assertNull($factura->totalBruto);
    }

    public function test_facturas_query_apunta_a_facturasventa(): void
    {
        $this->assertStringContainsString('FACTURASVENTA', FacturaExtractor::facturasQuery());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function facturaRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'       => 'A',
            'NUMFACTURA'     => 9001,
            'N'              => 'B',
            'CODCLIENTE'     => 555,
            'CODVENDEDOR'    => 77,
            'FECHA'          => '2026-01-15 10:30:00',
            'TOTALNETO'      => 1500.50,
            'TOTALIMPUESTOS' => 105.03,
            'TOTALBRUTO'     => 1605.53,
            'SUFACTURA'      => 'REF-9001',
        ], $overrides);
    }
}
