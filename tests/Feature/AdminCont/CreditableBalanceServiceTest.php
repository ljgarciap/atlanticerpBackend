<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\CreditableBalanceService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1116 (P1) — fuente única del valor acreditable restante (VAR) de una factura.
 *
 * VAR = factura.monto − Σ notas (todos los tipos) en estado aplicada o pendiente_aprobacion. Los casos del ticket
 * están acá tal cual (factura de 1.000), más los de cobros, las pendientes (D1), la nota excluida y el caso REAL de
 * producción: factura de 137,77 con una devolución de 128,76 → quedan 9,01.
 */
class CreditableBalanceServiceTest extends FeatureTestCase
{
    private CreditableBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->service = new CreditableBalanceService();
    }

    private function factura(float $monto, float $saldoAplicado = 0.0): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente VAR ' . uniqid()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub ' . uniqid(), 'tax_id' => '155-0000-1-' . random_int(1000, 9999)]);
        SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto ' . uniqid()]);
        $order = Order::create([
            'order_number' => 'PED-2026-' . random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name,
        ]);
        $rate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        return AdminContInvoice::create([
            'numero' => 'F-' . static::uniqueTestNumber(), 'order_id' => $order->id, 'itbms_rate_id' => $rate->id,
            'master_client_id' => $master->id, 'cliente' => $sub->business_name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
    }

    private function nota(AdminContInvoice $f, float $monto, string $tipo = AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, string $estado = AdminContCreditNote::ESTADO_APLICADA): AdminContCreditNote
    {
        return AdminContCreditNote::create([
            'numero' => 'NC-' . static::uniqueTestNumber(), 'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
            'tipo' => $tipo, 'factura_origen_id' => $f->id, 'monto' => $monto, 'subtotal' => $monto, 'itbms' => 0,
            'motivo' => 'Prueba', 'estado' => $estado, 'registrado_por' => 1, 'fecha' => now(),
        ]);
    }

    // ── Los casos del ticket (factura de 1.000) ────────────────────────────────────────────────────────────────

    public function test_sin_notas_previas_el_valor_acreditable_es_el_total_de_la_factura(): void
    {
        $f = $this->factura(1000);

        $this->assertSame(1000.0, $this->service->valorAcreditableRestante($f));
        $this->assertSame(0.0, $this->service->notasPrevias($f->id));
    }

    public function test_con_una_nota_de_300_quedan_700(): void
    {
        $f = $this->factura(1000);
        $this->nota($f, 300);

        $this->assertSame(700.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_con_nota_de_300_mas_devolucion_de_200_quedan_500_cuentan_todos_los_tipos_no_solo_la_ultima(): void
    {
        $f = $this->factura(1000);
        $this->nota($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL);
        $this->nota($f, 200, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA);

        $this->assertSame(500.0, $this->service->valorAcreditableRestante($f));
        $this->assertSame(500.0, $this->service->notasPrevias($f->id));
    }

    public function test_una_factura_ya_anulada_por_completo_queda_en_cero(): void
    {
        $f = $this->factura(1000);
        $this->nota($f, 1000, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $this->assertSame(0.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_caso_real_de_produccion_factura_de_13777_con_devolucion_de_12876_deja_901(): void
    {
        $f = $this->factura(137.77);
        $this->nota($f, 128.76, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA);

        $this->assertSame(9.01, $this->service->valorAcreditableRestante($f));
    }

    // ── Qué notas cuentan ──────────────────────────────────────────────────────────────────────────────────────

    public function test_las_notas_pendientes_de_aprobacion_reservan_valor(): void
    {
        $f = $this->factura(1000);
        $this->nota($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);

        $this->assertSame(700.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_las_notas_rechazadas_nunca_cuentan_y_liberan_el_valor(): void
    {
        $f = $this->factura(1000);
        $this->nota($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, AdminContCreditNote::ESTADO_RECHAZADA);

        $this->assertSame(1000.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_al_rechazarse_una_pendiente_su_valor_se_libera(): void
    {
        $f    = $this->factura(1000);
        $nota = $this->nota($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);
        $this->assertSame(700.0, $this->service->valorAcreditableRestante($f));

        $nota->update(['estado' => AdminContCreditNote::ESTADO_RECHAZADA]);

        $this->assertSame(1000.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_la_nota_excluida_no_cuenta_contra_si_misma(): void
    {
        $f     = $this->factura(1000);
        $otra  = $this->nota($f, 300);
        $propia = $this->nota($f, 700, AdminContCreditNote::TIPO_ANULACION_COMPLETA, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);

        $this->assertSame(0.0, $this->service->valorAcreditableRestante($f));                        // con la propia: no queda nada
        $this->assertSame(700.0, $this->service->valorAcreditableRestante($f, $propia->id));          // sin ella: lo que ella misma anularía
        $this->assertSame(300.0, $this->service->notasPrevias($f->id, $propia->id));
        $this->assertGreaterThan(0, $otra->id);
    }

    public function test_las_notas_de_otra_factura_no_se_mezclan(): void
    {
        $a = $this->factura(1000);
        $b = $this->factura(1000);
        $this->nota($b, 400);

        $this->assertSame(1000.0, $this->service->valorAcreditableRestante($a));
        $this->assertSame(600.0, $this->service->valorAcreditableRestante($b));
    }

    // ── Cobros y datos históricos ──────────────────────────────────────────────────────────────────────────────

    public function test_los_cobros_no_reducen_el_valor_acreditable_a_diferencia_del_saldo_por_cobrar(): void
    {
        // Factura de 1.000 cobrada 500 y sin notas: se puede anular por 1.000 (se devuelven los 500 cobrados).
        $cobradaAMedias = $this->factura(1000, saldoAplicado: 500);
        $this->assertSame(1000.0, $this->service->valorAcreditableRestante($cobradaAMedias));

        // Cobro 500 + nota aplicada 300 (saldo_aplicado = 800): el VAR sigue siendo 1.000 − 300.
        $conNota = $this->factura(1000, saldoAplicado: 800);
        $this->nota($conNota, 300);
        $this->assertSame(700.0, $this->service->valorAcreditableRestante($conNota));
    }

    public function test_una_factura_que_ya_excede_su_valor_por_datos_historicos_queda_en_cero_y_no_negativo(): void
    {
        // Los datos reales de test (F-0036): devolución 128,76 + anulación 137,77 sobre una factura de 137,77.
        $f = $this->factura(137.77);
        $this->nota($f, 128.76, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA);
        $this->nota($f, 137.77, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $this->assertSame(266.53, $this->service->notasPrevias($f->id));
        $this->assertSame(0.0, $this->service->valorAcreditableRestante($f));
    }

    public function test_el_redondeo_a_dos_decimales_no_arrastra_error_de_coma_flotante(): void
    {
        $f = $this->factura(100.10);
        $this->nota($f, 33.33);
        $this->nota($f, 33.33);

        $this->assertSame(33.44, $this->service->valorAcreditableRestante($f));
    }

    // ── Versión en lote ────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_lote_devuelve_monto_notas_previas_y_acreditable_por_factura(): void
    {
        $sinNotas   = $this->factura(1000);
        $conNota    = $this->factura(1000);
        $conRechazo = $this->factura(500);
        $anulada    = $this->factura(200);
        $this->nota($conNota, 300);
        $this->nota($conNota, 200, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA);
        $this->nota($conRechazo, 100, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, AdminContCreditNote::ESTADO_RECHAZADA);
        $this->nota($anulada, 200, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $r = $this->service->valoresAcreditables([$sinNotas->id, $conNota->id, $conRechazo->id, $anulada->id, $sinNotas->id, 99999999]);

        $this->assertCount(4, $r); // sin duplicados y sin el id inexistente
        $this->assertSame(['monto' => 1000.0, 'notas_previas' => 0.0, 'acreditable' => 1000.0], $r[$sinNotas->id]);
        $this->assertSame(['monto' => 1000.0, 'notas_previas' => 500.0, 'acreditable' => 500.0], $r[$conNota->id]);
        $this->assertSame(['monto' => 500.0, 'notas_previas' => 0.0, 'acreditable' => 500.0], $r[$conRechazo->id]);
        $this->assertSame(['monto' => 200.0, 'notas_previas' => 200.0, 'acreditable' => 0.0], $r[$anulada->id]);
    }

    public function test_el_lote_coincide_con_la_version_individual_para_cada_factura(): void
    {
        $a = $this->factura(1000);
        $b = $this->factura(137.77);
        $this->nota($a, 300);
        $this->nota($b, 128.76, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);

        $lote = $this->service->valoresAcreditables([$a->id, $b->id]);

        $this->assertSame($this->service->valorAcreditableRestante($a), $lote[$a->id]['acreditable']);
        $this->assertSame($this->service->valorAcreditableRestante($b), $lote[$b->id]['acreditable']);
    }

    public function test_el_lote_usa_dos_consultas_sin_importar_cuantas_facturas_haya(): void
    {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $f = $this->factura(1000);
            $this->nota($f, 100);
            $ids[] = $f->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->valoresAcreditables($ids);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $consultas, 'una consulta de montos de facturas y una de notas agrupadas, sin N+1');
    }

    public function test_el_lote_vacio_no_consulta_nada(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame([], $this->service->valoresAcreditables([]));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
