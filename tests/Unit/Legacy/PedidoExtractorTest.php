<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Extractors\PedidoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-758 — tests puros de la capa de mapeo de `PedidoExtractor` (sin DB, sin conexión de red).
 * Mismo patrón que `CotizacionExtractorTest`.
 */
final class PedidoExtractorTest extends TestCase
{
    // ── mapPedidoRow() ──────────────────────────────────────────────────────────────────────

    public function test_map_pedido_row_mapea_llave_natural_y_campos_basicos(): void
    {
        $row = self::pedidoRow();

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertSame('A', $pedido->icgNumserie);
        $this->assertSame(2001, $pedido->icgNumpedido);
        $this->assertSame(1, $pedido->icgN);
        $this->assertSame(555, $pedido->icgCodcliente);
        $this->assertSame(1500.50, $pedido->totneto);
        $this->assertSame(77, $pedido->icgCodvendedor);
        $this->assertSame('P', $pedido->idestado);
        $this->assertSame('B', $pedido->icgSeriealbaran);
        $this->assertSame(3001, $pedido->icgNumeroalbaran);
    }

    public function test_map_pedido_row_codcliente_nulo_no_revienta(): void
    {
        $row = self::pedidoRow(['CODCLIENTE' => null]);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->icgCodcliente);
    }

    public function test_map_pedido_row_codvendedor_nulo(): void
    {
        $row = self::pedidoRow(['CODVENDEDOR' => null]);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->icgCodvendedor);
    }

    public function test_map_pedido_row_fechapedido_valida_se_parsea(): void
    {
        $row = self::pedidoRow(['FECHAPEDIDO' => '2026-01-15 10:30:00']);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNotNull($pedido->fechapedido);
        $this->assertSame('2026-01-15', $pedido->fechapedido->format('Y-m-d'));
    }

    public function test_map_pedido_row_fechaentrega_nula(): void
    {
        $row = self::pedidoRow(['FECHAENTREGA' => null]);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->fechaentrega);
    }

    public function test_map_pedido_row_fecha_malformada_no_revienta_el_resto_de_la_fila(): void
    {
        $row = self::pedidoRow(['FECHAPEDIDO' => 'no-es-una-fecha']);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->fechapedido);
        $this->assertSame(555, $pedido->icgCodcliente);
    }

    public function test_map_pedido_row_idestado_string_vacio_se_normaliza_a_null(): void
    {
        $row = self::pedidoRow(['IDESTADO' => '']);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->idestado);
    }

    public function test_map_pedido_row_link_albaran_nulo_no_revienta(): void
    {
        $row = self::pedidoRow(['SERIEALBARAN' => null, 'NUMEROALBARAN' => null]);

        $pedido = PedidoExtractor::mapPedidoRow($row);

        $this->assertNull($pedido->icgSeriealbaran);
        $this->assertNull($pedido->icgNumeroalbaran);
    }

    // ── mapItemRow() ────────────────────────────────────────────────────────────────────────

    public function test_map_item_row_mapea_llave_de_cabecera_y_campos_basicos(): void
    {
        $row = self::itemRow();

        $item = PedidoExtractor::mapItemRow($row);

        $this->assertSame('A', $item->icgNumserie);
        $this->assertSame(2001, $item->icgNumpedido);
        $this->assertSame(1, $item->icgN);
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

        $item = PedidoExtractor::mapItemRow($row);

        $this->assertNull($item->icgCodarticulo);
    }

    public function test_map_item_row_valores_numericos_nulos(): void
    {
        $row = self::itemRow(['UNIDADESTOTAL' => null, 'PRECIO' => null, 'TOTAL' => null]);

        $item = PedidoExtractor::mapItemRow($row);

        $this->assertNull($item->cantidad);
        $this->assertNull($item->precio);
        $this->assertNull($item->total);
    }

    // ── query builders (smoke — confirman que apuntan a las tablas correctas) ─────────────────

    public function test_pedidos_query_apunta_a_pedventacab(): void
    {
        $this->assertStringContainsString('PEDVENTACAB', PedidoExtractor::pedidosQuery());
    }

    public function test_items_page_query_apunta_a_pedventalin_paginada(): void
    {
        $query = PedidoExtractor::itemsPageQuery();

        $this->assertStringContainsString('PEDVENTALIN', $query);
        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('FETCH NEXT', $query);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function pedidoRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'      => 'A',
            'NUMPEDIDO'     => 2001,
            'N'             => 1,
            'CODCLIENTE'    => 555,
            'CODVENDEDOR'   => 77,
            'FECHAPEDIDO'   => '2026-01-15 10:30:00',
            'FECHAENTREGA'  => '2026-01-20 00:00:00',
            'TOTNETO'       => 1500.50,
            'IDESTADO'      => 'P',
            'SERIEALBARAN'  => 'B',
            'NUMEROALBARAN' => 3001,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function itemRow(array $overrides = []): array
    {
        return array_merge([
            'NUMSERIE'      => 'A',
            'NUMPEDIDO'     => 2001,
            'N'             => 1,
            'CODARTICULO'   => 4321,
            'REFERENCIA'    => 'REF-001',
            'DESCRIPCION'   => 'Lámpara LED',
            'UNIDADESTOTAL' => 3,
            'PRECIO'        => 100,
            'TOTAL'         => 300,
        ], $overrides);
    }
}
