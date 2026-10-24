<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\CotizacionExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-757 — tests puros de la capa de mapeo de `CotizacionExtractor` (sin DB, sin conexión de
 * red). Mismo patrón que `ClienteExtractorTest`/`ProductoExtractorTest`.
 */
final class CotizacionExtractorTest extends TestCase
{
    // ── mapCotizacionRow() ──────────────────────────────────────────────────────────────────

    public function test_map_cotizacion_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::cotizacionRow();

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertSame('A', $cotizacion->icgNumserie);
        $this->assertSame(1001, $cotizacion->icgNumpresupuesto);
        $this->assertSame(1, $cotizacion->icgN);
        $this->assertSame(2, $cotizacion->icgVersion);
        $this->assertSame(555, $cotizacion->icgCodcliente);
        $this->assertSame(1500.50, $cotizacion->totalNeto);
        $this->assertSame(1650.75, $cotizacion->totalBruto);
        $this->assertSame(77, $cotizacion->icgCodvendedor);
        $this->assertSame('P', $cotizacion->idestado);
        $this->assertSame('1', $cotizacion->supresupuesto);
    }

    public function test_map_cotizacion_row_codcliente_nulo_no_revienta(): void
    {
        $row = self::cotizacionRow(['CODCLIENTE' => null]);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->icgCodcliente);
    }

    public function test_map_cotizacion_row_codvendedor_nulo(): void
    {
        $row = self::cotizacionRow(['CODVENDEDOR' => null]);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->icgCodvendedor);
    }

    public function test_map_cotizacion_row_fecha_valida_se_parsea(): void
    {
        $row = self::cotizacionRow(['FECHA' => '2026-01-15 10:30:00']);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNotNull($cotizacion->fecha);
        $this->assertSame('2026-01-15', $cotizacion->fecha->format('Y-m-d'));
    }

    public function test_map_cotizacion_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::cotizacionRow(['FECHA' => 'no-es-una-fecha']);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->fecha);
        $this->assertSame(555, $cotizacion->icgCodcliente);
    }

    public function test_map_cotizacion_row_fecha_nula(): void
    {
        $row = self::cotizacionRow(['FECHA' => null]);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->fecha);
    }

    public function test_map_cotizacion_row_idestado_supresupuesto_string_vacio_se_normaliza_a_null(): void
    {
        $row = self::cotizacionRow(['IDESTADO' => '', 'SUPRESUPUESTO' => '  ']);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->idestado);
        $this->assertNull($cotizacion->supresupuesto);
    }

    // ── mapItemRow() ────────────────────────────────────────────────────────────────────────

    public function test_map_item_row_mapea_llave_de_cabecera_y_campos_basicos(): void
    {
        $row = self::itemRow();

        $item = CotizacionExtractor::mapItemRow($row);

        $this->assertSame('A', $item->icgNumserie);
        $this->assertSame(1001, $item->icgNumpresupuesto);
        $this->assertSame(1, $item->icgN);
        $this->assertSame(2, $item->icgVersion);
        $this->assertSame(4321, $item->icgCodarticulo);
        $this->assertSame('REF-001', $item->referencia);
        $this->assertSame('Lámpara LED', $item->descripcion);
        $this->assertSame(3.0, $item->cantidad);
        $this->assertSame(100.0, $item->precio);
        $this->assertSame(10.0, $item->descuento);
        $this->assertSame(270.0, $item->total);
        $this->assertSame(60.0, $item->costo);
    }

    public function test_map_item_row_codarticulo_nulo_no_revienta(): void
    {
        $row = self::itemRow(['CODARTICULO' => null]);

        $item = CotizacionExtractor::mapItemRow($row);

        $this->assertNull($item->icgCodarticulo);
    }

    public function test_map_item_row_valores_numericos_nulos(): void
    {
        $row = self::itemRow(['UNIDADESTOTAL' => null, 'PRECIO' => null, 'DTO' => null, 'TOTAL' => null, 'COSTE' => null]);

        $item = CotizacionExtractor::mapItemRow($row);

        $this->assertNull($item->cantidad);
        $this->assertNull($item->precio);
        $this->assertNull($item->descuento);
        $this->assertNull($item->total);
        $this->assertNull($item->costo);
    }

    // ── query builders (smoke — confirman que apuntan a las tablas correctas) ─────────────────

    public function test_cotizaciones_query_apunta_a_presupuestoscab(): void
    {
        $this->assertStringContainsString('PRESUPUESTOSCAB', CotizacionExtractor::cotizacionesQuery());
    }

    // ── SCRUM-842 — campos libres (PROYECTO/PROMOCION) ─────────────────────────────────────

    public function test_cotizaciones_query_hace_left_join_a_camposlibres(): void
    {
        $query = CotizacionExtractor::cotizacionesQuery();

        $this->assertStringContainsString('PRESUPUESTOSCAMPOSLIBRES', $query);
        $this->assertStringContainsString('LEFT JOIN', $query);
    }

    public function test_map_cotizacion_row_mapea_proyecto_y_promocion(): void
    {
        $row = self::cotizacionRow(['PROYECTO' => 'Torre Delta', 'PROMOCION' => 'Verano2026']);

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertSame('Torre Delta', $cotizacion->proyecto);
        $this->assertSame('Verano2026', $cotizacion->promocion);
    }

    public function test_map_cotizacion_row_sin_camposlibres_no_revienta(): void
    {
        $row = self::cotizacionRow();

        $cotizacion = CotizacionExtractor::mapCotizacionRow($row);

        $this->assertNull($cotizacion->proyecto);
        $this->assertNull($cotizacion->promocion);
    }

    public function test_items_page_query_apunta_a_presupuestoslin_paginada(): void
    {
        $query = CotizacionExtractor::itemsPageQuery();

        $this->assertStringContainsString('PRESUPUESTOSLIN', $query);
        // OFFSET...FETCH exige ORDER BY en SQL Server (§ hallazgo 2026-08-21) — sin esto la
        // paginación puede saltear o repetir filas entre una página y la siguiente.
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('FETCH NEXT', $query);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function cotizacionRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'        => 'A',
            'NUMPRESUPUESTO'  => 1001,
            'N'               => 1,
            'VERSION'         => 2,
            'CODCLIENTE'      => 555,
            'CODVENDEDOR'     => 77,
            'FECHA'           => '2026-01-15 10:30:00',
            'TOTNETO'         => 1500.50,
            'TOTBRUTO'        => 1650.75,
            'IDESTADO'        => 'P',
            'SUPRESUPUESTO'   => '1',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function itemRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'       => 'A',
            'NUMPRESUPUESTO' => 1001,
            'N'              => 1,
            'VERSION'        => 2,
            'CODARTICULO'    => 4321,
            'REFERENCIA'     => 'REF-001',
            'DESCRIPCION'    => 'Lámpara LED',
            'UNIDADESTOTAL'  => 3,
            'PRECIO'         => 100,
            'DTO'            => 10,
            'TOTAL'          => 270,
            'COSTE'          => 60,
        ], $overrides);
    }
}
