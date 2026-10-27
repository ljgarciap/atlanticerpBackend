<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentItemsBuilder;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\Order;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-929 (B3b Punto 3) — `FiscalDocumentItemsBuilder` arma los ítems para el PAC desde
 * `invoice_lines` (una `parcial` solo lo que salió de SU entrega) o, para facturas legadas sin
 * líneas, desde la cotización completa a la tasa única de la factura.
 */
final class FiscalDocumentItemsBuilderTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    private function builder(): FiscalDocumentItemsBuilder
    {
        return app(FiscalDocumentItemsBuilder::class);
    }

    /** Pedido de 3 entregas de 3 unidades (9 × 100.00 + ITBMS = 963.00), todas en Por Despachar. */
    private function familia(): array
    {
        return $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[3], [3], [3]]);
    }

    private function parcialDe(Order $root, Order $entrega): AdminContInvoice
    {
        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')
            ->where('order_id', $entrega->id)->where('tipo', AdminContInvoice::TIPO_PARCIAL)->firstOrFail();
    }

    public function test_una_parcial_solo_arma_items_de_su_propia_entrega_no_del_pedido_entero(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'items' => $quoteItems] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);

        $items = $this->builder()->build($parcial);

        $this->assertCount(1, $items, 'Un solo QuoteItem cotizado en este pedido.');
        $item = $items[0];
        $this->assertInstanceOf(FiscalDocumentItem::class, $item);
        $this->assertSame($quoteItems[0]->reference, $item->codigo);
        $this->assertSame(3.0, $item->cantidad, 'Solo lo entregado por ESTA entrega, no las 9 unidades del pedido.');
        $this->assertSame('01', $item->tasaItbmsCode, '7% -> código DGI 01.');
        $this->assertSame(0.0, $item->descuento);
        // 300 de base / 3 unidades = 100.00 exacto en este caso, y cuadra con subtotal sin residual.
        $this->assertEqualsWithDelta(300.0, $item->cantidad * $item->precioUnitario - $item->descuento, 0.0001);
    }

    public function test_precio_unitario_no_se_redondea_para_que_cantidad_por_precio_cuadre_con_el_subtotal(): void
    {
        // 7 unidades de 33.33 con 5% de descuento de línea -> subtotal no divide exacto entre 7.
        ['root' => $root] = $this->makePedido(
            [['desc' => 'Producto con descuento', 'qty' => 7, 'price' => 33.33, 'discount' => 5]],
            [[7]],
        );
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $item = $this->builder()->build($factura)[0];

        // El precio_unitario PERSISTIDO en la línea está redondeado a 2 decimales — el builder debe
        // ignorarlo y recalcular sin redondear, o cantidad×precio no cuadraría con subtotal.
        $lineaPersistida = $factura->lines->first();
        $this->assertNotEquals(
            round($lineaPersistida->subtotal, 10),
            round($item->cantidad * $lineaPersistida->precio_unitario, 10),
            'El precio_unitario persistido (redondeado) NO multiplica exacto — por eso el builder no lo usa.',
        );
        $this->assertEqualsWithDelta(
            $lineaPersistida->subtotal,
            $item->cantidad * $item->precioUnitario - $item->descuento,
            0.000001,
            'cantidad × precioUnitario − descuento == subtotal, al centavo, con el precio SIN redondear del builder.',
        );
    }

    public function test_factura_completo_normal_arma_un_item_por_linea_a_la_misma_tasa(): void
    {
        ['root' => $root] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();

        $items = $this->builder()->build($factura);

        $this->assertCount(1, $items);
        $this->assertSame(9.0, $items[0]->cantidad);
        $this->assertEqualsWithDelta(900.0, $items[0]->cantidad * $items[0]->precioUnitario, 0.0001);
    }

    public function test_factura_legada_sin_lineas_arma_items_desde_la_cotizacion_completa(): void
    {
        ['root' => $root, 'quote' => $quote, 'items' => $quoteItems] = $this->familia();
        // Simula una factura previa a SCRUM-929: sin invoice_lines, solo `monto` y `quote_id`.
        $legacy = AdminContInvoice::create([
            'numero' => 'F-LEGACY-'.self::uniqueTestNumber(), 'order_id' => $root->id,
            'quote_id' => $quote->id, 'itbms_rate_id' => ItbmsRateResolver::defaultRate()->id,
            'cliente' => 'Cliente legado', 'monto' => 963.0, 'saldo_aplicado' => 0.0,
        ]);
        $this->assertTrue($legacy->lines->isEmpty());

        $items = $this->builder()->build($legacy);

        $this->assertCount(1, $items, 'Un QuoteItem, ahora con TODA la cantidad cotizada (9), no solo una entrega.');
        $this->assertSame(9.0, $items[0]->cantidad);
        $this->assertSame($quoteItems[0]->reference, $items[0]->codigo);
    }

    /**
     * Guarda de la opción A (2026-10-05): MSL declararía la retención (3.5%) como ITBMS 7% y el total fiscal no
     * cuadraría con el de AtlanticERP, así que el mapper se niega a traducirla. Ver docblock de `ItbmsCodeMapper`.
     */
    public function test_linea_con_tasa_de_retencion_no_se_construye_hasta_confirmar_criterio_fiscal(): void
    {
        ['root' => $root] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $tasaRetencion = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->firstOrFail();
        AdminContInvoice::whereKey($factura->id)->update(['itbms_rate_id' => $tasaRetencion->id]);
        $factura->lines()->update(['itbms_rate_id' => $tasaRetencion->id]);

        $this->expectException(\App\Modules\AdminCont\FacturacionElectronica\Exceptions\UnmappableItbmsRateException::class);

        $this->builder()->build($factura->fresh());
    }

    /** Integración con el consumidor real: `NucJsonMapper` cuadra el TotalItem con la base, sin descuadre. */
    public function test_los_items_construidos_cuadran_con_nucjsonmapper(): void
    {
        ['root' => $root] = $this->makePedido(
            [['desc' => 'Producto con descuento', 'qty' => 7, 'price' => 33.33, 'discount' => 5]],
            [[7]],
        );
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $items   = $this->builder()->build($factura);

        $mapper = new NucJsonMapper();
        $mapped = $mapper->mapItems($items);

        $lineaPersistida = $factura->lines->first();
        $this->assertEqualsWithDelta($lineaPersistida->subtotal, (float) $mapped[0]['Totals']['TotalItem'], 0.001);
    }
}
