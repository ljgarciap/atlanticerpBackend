<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — ensamblado de `FiscalDocument` para Notas de Crédito: ítem sintético (credit_notes
 * no tiene líneas propias en AtlanticERP), referencia a la factura de origen (requiere CUFE real) y
 * `pagos[]` derivado de `resultado` (no es un cobro, es cómo se liquida el valor de la nota).
 */
final class FiscalDocumentBuilderNotaCreditoTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    private function builder(): FiscalDocumentBuilder
    {
        return app(FiscalDocumentBuilder::class);
    }

    private function facturaCertificada(): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $factura->forceFill(['cufe' => 'FE0120000155704849-2-2021-fake', 'fe_estado' => 'certificado'])->save();

        return $factura->fresh();
    }

    private function notaCredito(AdminContInvoice $factura, array $overrides = []): AdminContCreditNote
    {
        return AdminContCreditNote::create($overrides + [
            'numero' => 'NC-'.self::uniqueTestNumber(),
            'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA,
            'factura_origen_id' => $factura->id,
            'monto' => 107.00,
            'subtotal' => 100.00,
            'itbms' => 7.00,
            'motivo' => 'Devolución parcial de mercancía dañada',
            'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(),
            'fecha' => now()->toDateString(),
        ]);
    }

    public function test_ensambla_referencia_e_item_sintetico_desde_la_factura_de_origen(): void
    {
        $factura = $this->facturaCertificada();
        $nc      = $this->notaCredito($factura, ['resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame('nota_credito', $documento->tipoDocumento);
        $this->assertSame('NC-ILU', $documento->serie);
        // SCRUM-987 — la DGI rechaza un `numero` no puramente numérico (confirmado en vivo contra
        // MSL sandbox 2026-09-28): el folio interno ("NC-XXXX") se sigue mostrando igual en toda la
        // UI, pero el `numero` que sale hacia el PAC es solo la parte numérica (sin recortar ceros
        // a la izquierda — ver docblock de FiscalDocumentBuilder::numeroFiscal()).
        $this->assertSame(preg_replace('/\D/', '', $nc->numero) ?: '0', $documento->numero);
        $this->assertNotNull($documento->referencia);
        $this->assertSame($factura->cufe, $documento->referencia->cufe);
        $this->assertSame($nc->motivo, $documento->referencia->razon);

        $this->assertCount(1, $documento->items);
        $this->assertSame($nc->numero, $documento->items[0]->codigo);
        $this->assertSame($nc->motivo, $documento->items[0]->descripcion);
        $this->assertSame(1.0, $documento->items[0]->cantidad);
        $this->assertEqualsWithDelta(100.00, $documento->items[0]->precioUnitario, 0.001);
        $this->assertSame('01', $documento->items[0]->tasaItbmsCode, '7% -> código DGI 01, heredado de la factura de origen.');

        // Mismo receptor que la factura de origen (misma cadena Order->pipelineCard->subClient).
        $this->assertSame('01', $documento->receptor->tipo);
        $this->assertNotNull($documento->receptor->ruc);
    }

    /**
     * SCRUM-1008 — la DGI rechaza una NC cuya fecha de emisión no es posterior a la de la factura
     * que referencia. Con `fecha` (sin hora) una NC del mismo día salía a las 00:00, ANTES de la factura.
     */
    public function test_nc_del_mismo_dia_que_la_factura_se_emite_despues_de_la_referencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00', 'America/Panama'));
        $factura = $this->facturaCertificada();
        Carbon::setTestNow(Carbon::parse('2026-09-29 15:30:00', 'America/Panama'));
        $nota = $this->notaCredito($factura);

        $documento = $this->builder()->paraNotaCredito($nota);

        $this->assertGreaterThan($documento->referencia->fecha, $documento->fechaEmision);
        $this->assertSame('2026-09-29T15:30:00-05:00', $documento->fechaEmision->format('c'));
        $this->assertNotSame('00:00:00', $documento->fechaEmision->format('H:i:s')); // el bug: 00:00 del día de la NC
        Carbon::setTestNow();
    }

    public function test_nc_del_dia_siguiente_usa_su_propia_fecha_y_hora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 21:08:51', 'America/Panama'));
        $factura = $this->facturaCertificada();
        Carbon::setTestNow(Carbon::parse('2026-09-30 00:05:00', 'America/Panama'));
        $nota = $this->notaCredito($factura);

        $documento = $this->builder()->paraNotaCredito($nota);

        $this->assertSame('2026-09-30T00:05:00-05:00', $documento->fechaEmision->format('c'));
        $this->assertGreaterThan($documento->referencia->fecha, $documento->fechaEmision);
        Carbon::setTestNow();
    }

    public function test_salvaguarda_la_emision_nunca_es_anterior_ni_igual_a_la_referencia(): void
    {
        Log::spy();
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00', 'America/Panama'));
        $factura = $this->facturaCertificada();
        $nota = $this->notaCredito($factura); // mismo segundo que la factura: created_at igual a la referencia

        $documento = $this->builder()->paraNotaCredito($nota);

        $this->assertSame('2026-09-29T10:00:01-05:00', $documento->fechaEmision->format('c'));
        $this->assertGreaterThan($documento->referencia->fecha, $documento->fechaEmision);

        // Reloj desfasado: la nota quedó registrada ANTES de la factura referenciada.
        $nota->forceFill(['created_at' => Carbon::parse('2026-09-29 09:00:00', 'America/Panama')])->save();
        $documento = $this->builder()->paraNotaCredito($nota->fresh());

        $this->assertSame('2026-09-29T10:00:01-05:00', $documento->fechaEmision->format('c'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje, array $ctx): bool => str_contains($mensaje, 'se ajustó a referencia + 1 s') && $ctx['nota_credito_id'] === $nota->id,
        )->twice();
        Carbon::setTestNow();
    }

    public function test_falla_si_la_factura_de_origen_nunca_fue_certificada(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        // Sin cufe — nunca certificada.
        $nc = $this->notaCredito($factura);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nunca fue certificada/');

        $this->builder()->paraNotaCredito($nc);
    }

    public function test_resultado_aplicado_saldo_declara_credito(): void
    {
        $factura = $this->facturaCertificada();
        $nc      = $this->notaCredito($factura, ['resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertCount(1, $documento->pagos);
        $this->assertSame(MslPaymentMethodMapper::METODO_INTERNO_CREDITO, $documento->pagos[0]->metodoPagoInterno);
        $this->assertEqualsWithDelta(107.00, $documento->pagos[0]->monto, 0.001);
    }

    public function test_resultado_saldo_favor_declara_credito(): void
    {
        $factura = $this->facturaCertificada();
        $nc      = $this->notaCredito($factura, ['resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame(MslPaymentMethodMapper::METODO_INTERNO_CREDITO, $documento->pagos[0]->metodoPagoInterno);
    }

    public function test_resultado_devuelto_con_cuenta_bancaria_declara_transferencia(): void
    {
        $factura = $this->facturaCertificada();
        $cuenta  = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'ultimos_4_digitos' => '1234']);
        $nc      = $this->notaCredito($factura, [
            'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO,
            'cuenta_bancaria_salida_id' => $cuenta->id,
        ]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame('transferencia', $documento->pagos[0]->metodoPagoInterno);
    }

    public function test_resultado_devuelto_sin_cuenta_bancaria_declara_efectivo(): void
    {
        $factura = $this->facturaCertificada();
        $nc      = $this->notaCredito($factura, ['resultado' => AdminContCreditNote::RESULTADO_DEVUELTO]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame('efectivo', $documento->pagos[0]->metodoPagoInterno);
    }

    /**
     * SCRUM-1009 — en una "Corrección de datos" de ITBMS, `credit_notes.monto` es el total del REEMPLAZO (tasa
     * corregida) pero el ítem de la NC lleva la tasa de la factura ORIGINAL: `pagos[].monto` debe acreditar el
     * total de la original para que ítems y pagos cuadren (VALIDATE_B606_BALANCE en el sandbox).
     */
    public function test_nc_de_correccion_acredita_el_total_de_la_factura_original_en_pagos(): void
    {
        $factura = $this->facturaCertificada(); // 9 x 100 + 7% = 963.00
        $nc = $this->notaCredito($factura, [
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
        ]);
        $nc->forceFill(['monto' => 900.00, 'subtotal' => 900.00, 'itbms' => 0.00])->save(); // el reemplazo saldría exento

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertEqualsWithDelta((float) $factura->monto, $documento->pagos[0]->monto, 0.001);
        // Los ítems ahora son las líneas de la factura original (9 × 100), no un ítem sintético.
        $itemTotal = $documento->items[0]->cantidad * $documento->items[0]->precioUnitario * (1 + 0.07);
        $this->assertEqualsWithDelta($itemTotal, $documento->pagos[0]->monto, 0.01, 'Σ ítems (con la tasa original) == Σ pagos');
    }

    public function test_nc_que_no_es_correccion_sigue_usando_su_propio_monto_en_pagos(): void
    {
        $factura = $this->facturaCertificada();
        $nc = $this->notaCredito($factura);

        $this->assertEqualsWithDelta(107.00, $this->builder()->paraNotaCredito($nc)->pagos[0]->monto, 0.001);
    }

    /** SCRUM-1003 — la NC de una factura a un Consumidor Final lleva el mismo receptor (02, sin RUC ni DV) que la factura. */
    public function test_nc_de_un_consumidor_final_sale_con_receptor_02_sin_identificacion(): void
    {
        // SCRUM-1140 — el receptor se congela al facturar: el cliente ya es Consumidor Final cuando se crea la factura.
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $root->pipelineCard->subClient->update(['receptor_tipo' => '02', 'tax_id' => '', 'tax_id_dv' => null]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $factura->forceFill(['cufe' => 'FE0120000155704849-2-2021-fake', 'fe_estado' => 'certificado'])->save();
        $nc = $this->notaCredito($factura->fresh());

        $receptor = $this->builder()->paraNotaCredito($nc)->receptor;

        $this->assertSame('02', $receptor->tipo);
        $this->assertSame('CONSUMIDOR FINAL', $receptor->nombre);
        $this->assertNull($receptor->ruc);
        $this->assertNull($receptor->dv);
    }

    /** SCRUM-1140 — la NC lleva el receptor CONGELADO de la factura de origen (misma razón social de la DGI), no el del cliente de hoy. */
    public function test_nc_hereda_el_receptor_congelado_de_la_factura_de_origen(): void
    {
        $factura = $this->facturaCertificada();
        $factura->forceFill([
            'receptor_tipo' => '01', 'receptor_ruc' => '155704849-2-2021', 'receptor_dv' => '32',
            'receptor_nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', 'receptor_validado_at' => now(),
        ])->save();
        Order::find($factura->family_root_id)?->pipelineCard?->subClient?->update(['business_name' => 'NOMBRE CAMBIADO DESPUES', 'tax_id_dv' => '11']);
        $nc = $this->notaCredito($factura->fresh());

        $receptor = $this->builder()->paraNotaCredito($nc)->receptor;

        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $receptor->nombre);
        $this->assertSame('155704849-2-2021', $receptor->ruc);
        $this->assertSame('32', $receptor->dv);
    }

    /** @return array{0: AdminContInvoice, 1: CustomerReturn} factura certificada de 9 × 100 y una devolución finalizada */
    private function facturaYDevolucion(array $lineas): array
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $factura->forceFill(['cufe' => 'FE0120000155704849-2-2021-fake', 'fe_estado' => 'certificado'])->save();

        $devolucion = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.self::uniqueTestNumber(), 'order_id' => $root->id,
            'status' => CustomerReturn::STATUS_FINALIZADO, 'finalized_at' => now(),
            'contact_name' => 'Contabilidad', 'contact_phone' => '6000-0000', 'created_by' => $this->ownerId(),
        ]);
        $orderItemId = $root->items()->firstOrFail()->id;
        foreach ($lineas as $l) {
            $devolucion->lines()->create([
                'order_item_id' => $orderItemId, 'reference_snapshot' => $l['ref'], 'description_snapshot' => $l['desc'],
                'unit_price' => $l['price'], 'qty_requested' => $l['qty'], 'qty_received' => $l['qty'], 'reason' => 'defectuoso',
            ]);
        }

        return [$factura->fresh(), $devolucion];
    }

    public function test_devolucion_de_bodega_viaja_con_las_lineas_realmente_devueltas(): void
    {
        [$factura, $devolucion] = $this->facturaYDevolucion([['ref' => 'ML-28012ABK', 'desc' => 'ITEM/RIEL MAGN', 'price' => 241.98, 'qty' => 2]]);
        $nc = $this->notaCredito($factura, [
            'monto' => 517.84, 'subtotal' => 483.96, 'itbms' => 33.88,
            'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO, 'devolucion_bodega_id' => $devolucion->id,
        ]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertCount(1, $documento->items);
        $this->assertSame('ML-28012ABK', $documento->items[0]->codigo);
        $this->assertSame('ITEM/RIEL MAGN', $documento->items[0]->descripcion);
        $this->assertSame(2.0, $documento->items[0]->cantidad);
        $this->assertEqualsWithDelta(241.98, $documento->items[0]->precioUnitario, 0.001);
        $this->assertSame('01', $documento->items[0]->tasaItbmsCode);
    }

    public function test_devolucion_cuyas_lineas_no_suman_el_subtotal_de_la_nota_falla_con_motivo(): void
    {
        [$factura, $devolucion] = $this->facturaYDevolucion([['ref' => 'ML-1', 'desc' => 'Panel', 'price' => 100.00, 'qty' => 2]]);
        $nc = $this->notaCredito($factura, ['subtotal' => 150.00, 'monto' => 160.50, 'itbms' => 10.50, 'devolucion_bodega_id' => $devolucion->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no suman el subtotal');
        $this->builder()->paraNotaCredito($nc);
    }

    public function test_devolucion_con_lineas_sin_precio_cae_al_item_sintetico(): void
    {
        [$factura, $devolucion] = $this->facturaYDevolucion([['ref' => 'ML-1', 'desc' => 'Panel', 'price' => null, 'qty' => 1]]);
        $nc = $this->notaCredito($factura, ['devolucion_bodega_id' => $devolucion->id]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame($nc->numero, $documento->items[0]->codigo);
    }

    public function test_anulacion_completa_copia_las_lineas_de_la_factura_de_origen(): void
    {
        $factura = $this->facturaCertificada();
        $nc = $this->notaCredito($factura, [
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'subtotal' => 900.00, 'monto' => 963.00, 'itbms' => 63.00,
        ]);

        $documento = $this->builder()->paraNotaCredito($nc);

        $this->assertSame('Panel XL', $documento->items[0]->descripcion);
        $this->assertSame(9.0, $documento->items[0]->cantidad);
        $this->assertEqualsWithDelta(100.00, $documento->items[0]->precioUnitario, 0.001);
    }
}
