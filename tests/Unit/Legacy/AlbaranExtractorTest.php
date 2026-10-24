<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\AlbaranExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-759 — tests puros de la capa de mapeo de `AlbaranExtractor` (sin DB, sin conexión de red).
 * Mismo patrón que `PedidoExtractorTest`.
 */
final class AlbaranExtractorTest extends TestCase
{
    // ── mapAlbaranRow() ─────────────────────────────────────────────────────────────────────

    public function test_map_albaran_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::albaranRow();

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertSame('A', $albaran->icgNumserie);
        $this->assertSame(5001, $albaran->icgNumalbaran);
        $this->assertSame('B', $albaran->icgN);
        $this->assertSame(555, $albaran->icgCodcliente);
        $this->assertSame(1500.50, $albaran->totalneto);
        $this->assertTrue($albaran->facturado);
        $this->assertSame(77, $albaran->icgCodvendedor);
        $this->assertSame('A', $albaran->icgNumseriefac);
        $this->assertSame(9001, $albaran->icgNumfac);
    }

    public function test_map_albaran_row_icg_n_es_string_no_int(): void
    {
        $row = self::albaranRow(['N' => 'B']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertIsString($albaran->icgN);
        $this->assertSame('B', $albaran->icgN);
    }

    public function test_map_albaran_row_codcliente_nulo_no_revienta(): void
    {
        $row = self::albaranRow(['CODCLIENTE' => null]);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->icgCodcliente);
    }

    public function test_map_albaran_row_codvendedor_nulo(): void
    {
        $row = self::albaranRow(['CODVENDEDOR' => null]);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->icgCodvendedor);
    }

    public function test_map_albaran_row_fecha_valida_se_parsea(): void
    {
        $row = self::albaranRow(['FECHA' => '2026-01-15 10:30:00']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNotNull($albaran->fecha);
        $this->assertSame('2026-01-15', $albaran->fecha->format('Y-m-d'));
    }

    public function test_map_albaran_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::albaranRow(['FECHA' => 'no-es-una-fecha']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->fecha);
        $this->assertSame(555, $albaran->icgCodcliente);
    }

    public function test_map_albaran_row_facturado_t_es_true(): void
    {
        $row = self::albaranRow(['FACTURADO' => 'T']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertTrue($albaran->facturado);
    }

    public function test_map_albaran_row_facturado_f_es_false(): void
    {
        $row = self::albaranRow(['FACTURADO' => 'F']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertFalse($albaran->facturado);
    }

    /**
     * Mismo gotcha que `ClienteExtractor::bloqueado` (§13.2 del diseño) — 'V' es el literal de
     * `ARTICULOS.DESCATALOGADO`, NUNCA el de `ALBVENTACAB.FACTURADO`. Cualquier valor que no sea
     * exactamente 'T' se interpreta como false, nunca se asume 'V' como verdadero acá.
     */
    public function test_map_albaran_row_facturado_v_no_es_true(): void
    {
        $row = self::albaranRow(['FACTURADO' => 'V']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertFalse($albaran->facturado);
    }

    public function test_map_albaran_row_link_factura_nulo_no_revienta(): void
    {
        $row = self::albaranRow(['NUMSERIEFAC' => null, 'NUMFAC' => null]);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->icgNumseriefac);
        $this->assertNull($albaran->icgNumfac);
    }

    // ── mapItemRow() ────────────────────────────────────────────────────────────────────────

    public function test_map_item_row_mapea_llave_de_cabecera_y_campos_basicos(): void
    {
        $row = self::itemRow();

        $item = AlbaranExtractor::mapItemRow($row);

        $this->assertSame('A', $item->icgNumserie);
        $this->assertSame(5001, $item->icgNumalbaran);
        $this->assertSame('B', $item->icgN);
        $this->assertSame(4321, $item->icgCodarticulo);
        $this->assertSame('REF-001', $item->referencia);
        $this->assertSame('Lámpara LED', $item->descripcion);
        $this->assertSame(3.0, $item->cantidad);
        $this->assertSame(100.0, $item->precio);
        $this->assertSame(300.0, $item->total);
    }

    public function test_map_item_row_codarticulo_nulo_no_revienta(): void
    {
        $row = self::itemRow(['CODARTICULO' => null]);

        $item = AlbaranExtractor::mapItemRow($row);

        $this->assertNull($item->icgCodarticulo);
    }

    public function test_map_item_row_valores_numericos_nulos(): void
    {
        $row = self::itemRow(['UNIDADESTOTAL' => null, 'PRECIO' => null, 'TOTAL' => null]);

        $item = AlbaranExtractor::mapItemRow($row);

        $this->assertNull($item->cantidad);
        $this->assertNull($item->precio);
        $this->assertNull($item->total);
    }

    // ── query builders (smoke — confirman que apuntan a las tablas correctas) ─────────────────

    public function test_albaranes_query_apunta_a_albventacab(): void
    {
        $this->assertStringContainsString('ALBVENTACAB', AlbaranExtractor::albaranesQuery());
    }

    // ── SCRUM-842 — campo libre (OBSERVACIONES) ─────────────────────────────────────────────

    public function test_albaranes_query_hace_left_join_a_camposlibres(): void
    {
        $query = AlbaranExtractor::albaranesQuery();

        $this->assertStringContainsString('ALBVENTACAMPOSLIBRES', $query);
        $this->assertStringContainsString('LEFT JOIN', $query);
    }

    public function test_map_albaran_row_mapea_observaciones(): void
    {
        $row = self::albaranRow(['OBSERVACIONES' => 'Entregar en horario matutino']);

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertSame('Entregar en horario matutino', $albaran->observaciones);
    }

    public function test_map_albaran_row_sin_camposlibres_no_revienta(): void
    {
        $row = self::albaranRow();

        $albaran = AlbaranExtractor::mapAlbaranRow($row);

        $this->assertNull($albaran->observaciones);
    }

    public function test_items_page_query_apunta_a_albventalin_paginada(): void
    {
        $query = AlbaranExtractor::itemsPageQuery();

        $this->assertStringContainsString('ALBVENTALIN', $query);
        // OFFSET...FETCH exige ORDER BY en SQL Server (mismo hallazgo que CotizacionExtractor,
        // 2026-08-21) — sin esto la paginación puede saltear o repetir filas entre páginas.
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('FETCH NEXT', $query);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function albaranRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'    => 'A',
            'NUMALBARAN'  => 5001,
            'N'           => 'B',
            'CODCLIENTE'  => 555,
            'FECHA'       => '2026-01-15 10:30:00',
            'TOTALNETO'   => 1500.50,
            'FACTURADO'   => 'T',
            'CODVENDEDOR' => 77,
            'NUMSERIEFAC' => 'A',
            'NUMFAC'      => 9001,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function itemRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'      => 'A',
            'NUMALBARAN'    => 5001,
            'N'             => 'B',
            'CODARTICULO'   => 4321,
            'REFERENCIA'    => 'REF-001',
            'DESCRIPCION'   => 'Lámpara LED',
            'UNIDADESTOTAL' => 3,
            'PRECIO'        => 100,
            'TOTAL'         => 300,
        ], $overrides);
    }
}
