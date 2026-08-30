<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\ProductoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-750 — tests puros de la capa de mapeo de `ProductoExtractor` (sin DB, sin conexión de
 * red). Cubre el gotcha NDBOOLEANF (ARTICULOS.DESCATALOGADO texto 'V'/'F' vs
 * PRECIOSVENTA.DESCATALOGADO bit 0/1) y los casos borde del ticket SCRUM-750.
 */
final class ProductoExtractorTest extends TestCase
{
    // ── mapProductoRow() — ARTICULOS.DESCATALOGADO es TEXTO 'V'/'F' ────────────────────────────

    public function test_map_producto_row_interpreta_descatalogado_f_como_activo(): void
    {
        $row = self::articuloRow(['DESCATALOGADO' => 'F']);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertFalse($producto->descatalogado);
    }

    public function test_map_producto_row_interpreta_descatalogado_v_como_inactivo(): void
    {
        $row = self::articuloRow(['DESCATALOGADO' => 'V']);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertTrue($producto->descatalogado);
    }

    public function test_map_producto_row_no_confunde_v_f_con_0_1(): void
    {
        // Si alguien tratara DESCATALOGADO como bit por error, '0'/'1' pasarían silenciosamente.
        // Acá deben interpretarse como "no es 'V'" -> false, nunca lanzar ni confundir formato.
        $row = self::articuloRow(['DESCATALOGADO' => '0']);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertFalse($producto->descatalogado);
    }

    public function test_map_producto_row_combina_familia_y_subfamilia_crudos(): void
    {
        $row = self::articuloRow(['FAMILIA' => '12', 'SUBFAMILIA' => '34']);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertSame('12/34', $producto->familiaNombre);
    }

    public function test_map_producto_row_familia_nula_si_ambos_codigos_vacios(): void
    {
        $row = self::articuloRow(['FAMILIA' => null, 'SUBFAMILIA' => null]);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertNull($producto->familiaNombre);
    }

    public function test_map_producto_row_ref_proveedor_nulo_si_string_vacio(): void
    {
        $row = self::articuloRow(['REFPROVEEDOR' => '   ']);

        $producto = ProductoExtractor::mapProductoRow($row);

        $this->assertNull($producto->refProveedor);
    }

    public function test_map_producto_row_es_determinista(): void
    {
        $row = self::articuloRow();

        $first  = ProductoExtractor::mapProductoRow($row);
        $second = ProductoExtractor::mapProductoRow($row);

        $this->assertEquals($first, $second);
    }

    // ── mapPrecioRow() — PRECIOSVENTA.DESCATALOGADO es BIT real 0/1 ────────────────────────────

    public function test_map_precio_row_mapea_tarifa_publico(): void
    {
        $row = self::precioRow(['IDTARIFAV' => 1, 'PNETO' => 100.50, 'DESCATALOGADO' => 0]);

        $precio = ProductoExtractor::mapPrecioRow($row);

        $this->assertNotNull($precio);
        $this->assertSame(1, $precio->idtarifav);
        $this->assertSame(100.50, $precio->precioNeto);
        $this->assertFalse($precio->descatalogadoFila);
    }

    public function test_map_precio_row_mapea_las_4_tarifas_vigentes(): void
    {
        foreach ([1, 2, 3, 5] as $idtarifav) {
            $row    = self::precioRow(['IDTARIFAV' => $idtarifav]);
            $precio = ProductoExtractor::mapPrecioRow($row);

            $this->assertNotNull($precio, "IDTARIFAV={$idtarifav} debería mapear");
            $this->assertSame($idtarifav, $precio->idtarifav);
        }
    }

    public function test_map_precio_row_excluye_tarifa_4_dbahia_aunque_no_este_descatalogada(): void
    {
        // Defensa en profundidad: aunque ICG mandara la tarifa 4 con DESCATALOGADO=0 por error,
        // el mapper la excluye igual porque no está en PriceTypeMap.
        $row = self::precioRow(['IDTARIFAV' => 4, 'DESCATALOGADO' => 0]);

        $precio = ProductoExtractor::mapPrecioRow($row);

        $this->assertNull($precio);
    }

    public function test_map_precio_row_excluye_fila_con_descatalogado_bit_1(): void
    {
        $row = self::precioRow(['IDTARIFAV' => 1, 'DESCATALOGADO' => 1]);

        $precio = ProductoExtractor::mapPrecioRow($row);

        $this->assertNull($precio);
    }

    public function test_map_precio_row_excluye_tarifa_no_reconocida(): void
    {
        $row = self::precioRow(['IDTARIFAV' => 99]);

        $precio = ProductoExtractor::mapPrecioRow($row);

        $this->assertNull($precio);
    }

    public function test_map_precio_row_es_determinista(): void
    {
        $row = self::precioRow(['IDTARIFAV' => 1]);

        $first  = ProductoExtractor::mapPrecioRow($row);
        $second = ProductoExtractor::mapPrecioRow($row);

        $this->assertEquals($first, $second);
    }

    // ── mapCostoRow() — sin filtrar, captura todo ────────────────────────────────────────────

    public function test_map_costo_row_mapea_sin_filtrar(): void
    {
        $row = self::costoRow(['CODPROVEEDOR' => 55, 'CODARTICULO' => 1001, 'PNETO' => 42.75]);

        $costo = ProductoExtractor::mapCostoRow($row);

        $this->assertSame(55, $costo->codproveedor);
        $this->assertSame(1001, $costo->icgCodarticulo);
        $this->assertSame(42.75, $costo->costoNeto);
    }

    public function test_map_costo_row_es_determinista(): void
    {
        $row = self::costoRow();

        $first  = ProductoExtractor::mapCostoRow($row);
        $second = ProductoExtractor::mapCostoRow($row);

        $this->assertEquals($first, $second);
    }

    // ── queries — smoke test de que el filtro de activos/formato están presentes ───────────────

    public function test_articulos_query_filtra_descatalogado_f(): void
    {
        $this->assertStringContainsString("DESCATALOGADO = 'F'", ProductoExtractor::articulosQuery());
    }

    public function test_precios_query_filtra_talla_color_codformato_fijos(): void
    {
        $sql = ProductoExtractor::preciosQuery();

        $this->assertStringContainsString("TALLA = '.'", $sql);
        $this->assertStringContainsString("COLOR = '.'", $sql);
        $this->assertStringContainsString('CODFORMATO = 0', $sql);
    }

    public function test_costos_query_hace_join_a_articulos_activos(): void
    {
        $sql = ProductoExtractor::costosQuery();

        $this->assertStringContainsString('INNER JOIN ARTICULOS', $sql);
        $this->assertStringContainsString("DESCATALOGADO = 'F'", $sql);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function articuloRow(array $overrides = []): array
    {
        return array_merge([
            'CODARTICULO'  => 1001,
            'DESCRIPCION'  => 'Lámpara colgante LED',
            'MARCA'        => '12',
            'FAMILIA'      => '3',
            'SUBFAMILIA'   => '7',
            'REFPROVEEDOR' => '55',
            'DESCATALOGADO' => 'F',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function precioRow(array $overrides = []): array
    {
        return array_merge([
            'CODARTICULO'   => 1001,
            'IDTARIFAV'     => 1,
            'PNETO'         => 100.0,
            'PBRUTO'        => 119.0,
            'DTO'           => 0.0,
            'DESCATALOGADO' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function costoRow(array $overrides = []): array
    {
        return array_merge([
            'CODPROVEEDOR' => 55,
            'CODARTICULO'  => 1001,
            'PNETO'        => 60.0,
        ], $overrides);
    }
}
