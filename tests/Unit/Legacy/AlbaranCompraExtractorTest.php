<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\AlbaranCompraExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-840 — tests puros de la capa de mapeo de `AlbaranCompraExtractor` (sin DB, sin conexión de
 * red). Mismo patrón que `AlbaranExtractorTest`.
 */
final class AlbaranCompraExtractorTest extends TestCase
{
    // ── mapAlbaranRow() ─────────────────────────────────────────────────────────────────────

    public function test_map_albaran_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::albaranRow();

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertSame('A', $albaran->icgNumserie);
        $this->assertSame(3001, $albaran->icgNumalbaran);
        $this->assertSame('B', $albaran->icgN);
        $this->assertSame(42, $albaran->icgCodproveedor);
        $this->assertSame(850.75, $albaran->totalneto);
        $this->assertTrue($albaran->facturado);
        $this->assertSame('A', $albaran->icgNumseriefac);
        $this->assertSame(7001, $albaran->icgNumfac);
    }

    public function test_map_albaran_row_icg_n_es_string_no_int(): void
    {
        $row = self::albaranRow(['N' => 'B']);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertIsString($albaran->icgN);
        $this->assertSame('B', $albaran->icgN);
    }

    public function test_map_albaran_row_codproveedor_nulo_no_revienta(): void
    {
        $row = self::albaranRow(['CODPROVEEDOR' => null]);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->icgCodproveedor);
    }

    public function test_map_albaran_row_fecha_valida_se_parsea(): void
    {
        $row = self::albaranRow(['FECHAALBARAN' => '2026-02-10 09:00:00']);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertNotNull($albaran->fecha);
        $this->assertSame('2026-02-10', $albaran->fecha->format('Y-m-d'));
    }

    public function test_map_albaran_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::albaranRow(['FECHAALBARAN' => 'no-es-una-fecha']);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->fecha);
        $this->assertSame(42, $albaran->icgCodproveedor);
    }

    public function test_map_albaran_row_facturado_t_es_true(): void
    {
        $row = self::albaranRow(['FACTURADO' => 'T']);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertTrue($albaran->facturado);
    }

    public function test_map_albaran_row_facturado_f_es_false(): void
    {
        $row = self::albaranRow(['FACTURADO' => 'F']);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertFalse($albaran->facturado);
    }

    public function test_map_albaran_row_link_factura_nulo_no_revienta(): void
    {
        $row = self::albaranRow(['NUMSERIEFAC' => null, 'NUMFAC' => null]);

        $albaran = AlbaranCompraExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->icgNumseriefac);
        $this->assertNull($albaran->icgNumfac);
    }

    // ── mapItemRow() ────────────────────────────────────────────────────────────────────────

    public function test_map_item_row_mapea_llave_de_cabecera_y_campos_basicos(): void
    {
        $row = self::itemRow();

        $item = AlbaranCompraExtractor::mapItemRow($row);

        $this->assertSame('A', $item->icgNumserie);
        $this->assertSame(3001, $item->icgNumalbaran);
        $this->assertSame('B', $item->icgN);
        $this->assertSame(1234, $item->icgCodarticulo);
        $this->assertSame('REF-COMPRA-01', $item->referencia);
        $this->assertSame('Perfil de aluminio', $item->descripcion);
        $this->assertSame(10.0, $item->cantidad);
        $this->assertSame(25.0, $item->precio);
        $this->assertSame(250.0, $item->total);
    }

    public function test_map_item_row_codarticulo_nulo_no_revienta(): void
    {
        $row = self::itemRow(['CODARTICULO' => null]);

        $item = AlbaranCompraExtractor::mapItemRow($row);

        $this->assertNull($item->icgCodarticulo);
    }

    public function test_map_item_row_valores_numericos_nulos(): void
    {
        $row = self::itemRow(['UNIDADESTOTAL' => null, 'PRECIO' => null, 'TOTAL' => null]);

        $item = AlbaranCompraExtractor::mapItemRow($row);

        $this->assertNull($item->cantidad);
        $this->assertNull($item->precio);
        $this->assertNull($item->total);
    }

    // ── query builders (smoke) ──────────────────────────────────────────────────────────────

    public function test_albaranes_query_apunta_a_albcompracab(): void
    {
        $this->assertStringContainsString('ALBCOMPRACAB', AlbaranCompraExtractor::albaranesQuery());
    }

    public function test_items_page_query_apunta_a_albcompralin_paginada(): void
    {
        $query = AlbaranCompraExtractor::itemsPageQuery();

        $this->assertStringContainsString('ALBCOMPRALIN', $query);
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('FETCH NEXT', $query);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function albaranRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'     => 'A',
            'NUMALBARAN'   => 3001,
            'N'            => 'B',
            'CODPROVEEDOR' => 42,
            'FECHAALBARAN' => '2026-02-10 09:00:00',
            'TOTALNETO'    => 850.75,
            'FACTURADO'    => 'T',
            'NUMSERIEFAC'  => 'A',
            'NUMFAC'       => 7001,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function itemRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'      => 'A',
            'NUMALBARAN'    => 3001,
            'N'             => 'B',
            'CODARTICULO'   => 1234,
            'REFERENCIA'    => 'REF-COMPRA-01',
            'DESCRIPCION'   => 'Perfil de aluminio',
            'UNIDADESTOTAL' => 10,
            'PRECIO'        => 25,
            'TOTAL'         => 250,
        ], $overrides);
    }
}
