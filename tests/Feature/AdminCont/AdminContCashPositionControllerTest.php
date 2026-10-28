<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentConfirmation;
use App\Modules\AdminCont\Services\DailyCashCountService;
use App\Modules\AdminCont\Services\DailyCashCountSummaryPdfService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AdminCont\Concerns\ReadsXlsxResponse;
use Tests\Feature\FeatureTestCase;

/** Batch 18 (SCRUM-597→601, REQ-520→524) — ver ADR-SCRUM597-601-batch18-arqueo-caja.md. */
class AdminContCashPositionControllerTest extends FeatureTestCase
{
    use ReadsXlsxResponse;

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    // ---------------------------------------------------------------- REQ-520 header ----

    public function test_header_suma_bancos_activos_mas_caja_menuda_e_ignora_cuenta_inactiva(): void
    {
        $activa = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        $inactiva = AdminContBankAccount::create(['banco' => 'Banco Demo C', 'ultimos_4_digitos' => '1111', 'activa' => false]);

        AdminContBankMovement::create(['bank_account_id' => $activa->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => $activa->id, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 100]);
        AdminContBankMovement::create(['bank_account_id' => $inactiva->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro cuenta inactiva', 'monto' => 9999]);

        AdminContFiscalSettings::current()->update(['petty_cash_balance' => 340]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position')
            ->assertStatus(200);

        $response->assertJson([
            'saldo_bancos' => 400.0,
            'saldo_caja_menuda' => 340.0,
            'saldo_disponible_hoy' => 740.0,
        ]);
    }

    public function test_879_comision_sin_cuenta_asignada_cuenta_en_saldo_de_bancos_y_vista_real(): void
    {
        $activa = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $activa->id, 'fecha' => now()->subDays(3), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 1000]);
        // Comisión pagada SIN cuenta asignada — igual es salida real de dinero (E-3).
        AdminContBankMovement::create(['bank_account_id' => null, 'fecha' => now()->subDays(2), 'tipo' => 'comision', 'concepto' => 'Comisión interna pagada — Vendedor X (jun 2026)', 'monto' => 250, 'source_type' => 'internal_commission', 'source_id' => 1]);

        $token = $this->loginAsFelix();

        // saldo_bancos: 1000 cobro - 250 comisión sin cuenta = 750.
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position')
            ->assertStatus(200)
            ->assertJson(['saldo_bancos' => 750.0]);

        // Vista Real incluye el movimiento de comisión sin cuenta como salida.
        $real = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/real?window=30')
            ->assertStatus(200)
            ->json();
        $conceptos = array_column($real['movimientos'], 'concepto');
        $this->assertContains('Comisión interna pagada — Vendedor X (jun 2026)', $conceptos);
        // La última fila cierra en saldo_actual (invariante de real()).
        $this->assertSame($real['saldo_actual'], end($real['movimientos'])['saldo_acumulado']);
    }

    // ---------------------------------------------------------------- REQ-521/522 projected, gates ----

    /**
     * Aclarado por Luis 2026-08-25 (Pre-QA Batch 18): REQ-521 le veda a Asistente Administrativa el detalle línea
     * por línea, no los 3 totales agregados del encabezado (esos son de REQ-520, que sí la
     * incluye) — antes esta prueba esperaba 403 acá, ahora espera 200 con entradas/salidas vacíos
     * pero los totales calculados sobre el dato completo.
     */
    public function test_projected_permite_a_asistente_pero_sin_el_detalle_linea_por_linea(): void
    {
        AdminContInvoice::create(['numero' => 'F-9199', 'order_id' => 919900, 'cliente' => 'Cliente Asistente Administrativa', 'monto' => 750]);

        $token = $this->loginAsYaneth();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $response->assertJson(['entradas' => [], 'salidas' => []]);
        $this->assertEquals(750.0, $response->json('total_entradas'));
    }

    public function test_real_rechaza_a_asistente(): void
    {
        $token = $this->loginAsYaneth();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/real?window=30')
            ->assertStatus(403);
    }

    public function test_projected_incluye_factura_atrasada_y_proxima_ambas_con_saldo_pendiente(): void
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        $proxima = AdminContInvoice::create(['numero' => 'F-9101', 'order_id' => 910100, 'cliente' => 'Cliente Próximo', 'monto' => 1000]);
        $atrasada = AdminContInvoice::create(['numero' => 'F-9102', 'order_id' => 910101, 'cliente' => 'Cliente Atrasado', 'monto' => 2000]);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        // vence dentro de la ventana (recién creada, created_at=hoy → vencimiento en `diasCredito` días).
        DB::table("{$slug}_admin_contab.invoices")->where('id', $atrasada->id)
            ->update(['created_at' => now()->subDays($diasCredito + 20)]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $byReferencia = collect($response->json('entradas'))->keyBy('referencia');
        $this->assertSame('proximo', $byReferencia['F-9101']['vencimiento']);
        $this->assertGreaterThanOrEqual(0, $byReferencia['F-9101']['dias']);
        $this->assertSame('atrasado', $byReferencia['F-9102']['vencimiento']);
        $this->assertLessThan(0, $byReferencia['F-9102']['dias']);
        $this->assertEquals(2000.0, $byReferencia['F-9102']['monto']);
    }

    public function test_projected_excluye_factura_incobrable_y_ya_saldada(): void
    {
        AdminContInvoice::create(['numero' => 'F-9103', 'order_id' => 910102, 'cliente' => 'Incobrable', 'monto' => 500, 'es_incobrable' => true]);
        AdminContInvoice::create(['numero' => 'F-9104', 'order_id' => 910103, 'cliente' => 'Saldada', 'monto' => 500, 'saldo_aplicado' => 500]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $referencias = collect($response->json('entradas'))->pluck('referencia');
        $this->assertNotContains('F-9103', $referencias);
        $this->assertNotContains('F-9104', $referencias);
    }

    public function test_projected_salidas_incluye_nota_credito_pendiente_con_excedente_calculado(): void
    {
        $userId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $invoice = AdminContInvoice::create(['numero' => 'F-9105', 'order_id' => 910104, 'cliente' => 'Fam. Herrera', 'monto' => 1000, 'saldo_aplicado' => 800]);

        AdminContCreditNote::create([
            'numero' => 'NC-9001', 'master_client_id' => 1, 'cliente' => 'Fam. Herrera',
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $invoice->id,
            'monto' => 350, 'subtotal' => 350, 'itbms' => 0, 'motivo' => 'Test',
            'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
            'registrado_por' => $userId, 'fecha' => now()->subDays(5)->toDateString(),
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $byTipo = collect($response->json('salidas'))->keyBy('tipo');
        $this->assertArrayHasKey('nota_credito', $byTipo);
        $this->assertEquals(150.0, $byTipo['nota_credito']['monto']); // 350 - (1000-800) saldo pendiente
        $this->assertSame('atrasado', $byTipo['nota_credito']['vencimiento']);
    }

    // ---------------------------------------------------------------- REQ-523 real ----

    public function test_real_calcula_saldo_acumulado_cronologico_y_respeta_la_ventana(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);

        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now()->subDays(40), 'tipo' => 'cobro', 'concepto' => 'Fuera de ventana', 'monto' => 9999]);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now()->subDays(10), 'tipo' => 'cobro', 'concepto' => 'Cobro viejo', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now()->subDays(2), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 100]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/real?window=30')
            ->assertStatus(200);

        // saldo_actual (bankBalance(), nunca acotado por ventana) = 9999 + 500 - 100 = 10399 —
        // el movimiento de hace 40 días SÍ suma al saldo real, solo queda fuera de la TABLA.
        $movimientos = $response->json('movimientos');
        $this->assertCount(2, $movimientos, 'no debe incluir el movimiento de hace 40 días en la tabla');
        $this->assertEquals(500.0, $movimientos[0]['entrada']);
        $this->assertEquals(9999 + 500, $movimientos[0]['saldo_acumulado']);
        $this->assertEquals(100.0, $movimientos[1]['salida']);
        $this->assertEquals(9999 + 500 - 100, $movimientos[1]['saldo_acumulado']);
        $this->assertEquals(10399.0, $response->json('saldo_actual'));
    }

    public function test_real_rechaza_window_0(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/real?window=0')
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- REQ-520 RN3 export ----

    public function test_export_rechaza_real_mas_hoy(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/export?view=real&window=0&format=pdf')
            ->assertStatus(422);
    }

    /**
     * Defensa en profundidad (Pre-QA Batch 18, 2026-08-25): el PDF/Excel de Proyectado lleva el
     * detalle completo sin la limpieza que sí aplica `projected()` — a diferencia de esa ruta,
     * acá no hay versión "solo totales" para Asistente Administrativa, así que la ruta se rechaza directo.
     */
    public function test_export_rechaza_a_asistente(): void
    {
        $token = $this->loginAsYaneth();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/export?view=proyectado&window=30&format=pdf')
            ->assertStatus(403);
    }

    public function test_export_pdf_proyectado_devuelve_pdf(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/export?view=proyectado&window=30&format=pdf')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_excel_real_devuelve_xlsx(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/export?view=real&window=30&format=excel')
            ->assertStatus(200);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    // ---------------------------------------------------------- SCRUM-939 formato de moneda ----

    public function test_939_excel_real_escribe_entrada_salida_y_saldo_como_celdas_numericas(): void
    {
        $account = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9036']);
        AdminContBankMovement::create(['bank_account_id' => $account->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 300]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/cash-position/export?view=real&window=30&format=excel')
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();

        $this->assertSame(['Fecha', 'Concepto', 'Origen', 'Entrada', 'Salida', 'Saldo acumulado'], $sheet->toArray()[0]);
        $this->assertMoneyCell($sheet, 'D2', 300.0);
        $this->assertMoneyCell($sheet, 'E2', 0.0);
        $this->assertMoneyCell($sheet, 'F2', 300.0);
    }

    public function test_939_excel_proyectado_escribe_monto_y_neto_como_celdas_numericas(): void
    {
        AdminContInvoice::create(['numero' => 'F-9201', 'order_id' => 920100, 'cliente' => 'Cliente Test', 'monto' => 1000]);
        $token = $this->loginAsFelix();

        $neto = (float) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200)
            ->json('neto');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/cash-position/export?view=proyectado&window=30&format=excel')
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $rows = $sheet->toArray();

        $this->assertSame(['Tipo', 'Concepto', 'Referencia', 'Monto'], $rows[0]);
        $this->assertMoneyCell($sheet, 'D2', 1000.0);

        $netoRow = null;
        foreach ($rows as $i => $row) {
            if (($row[2] ?? null) === 'Neto proyectado') {
                $netoRow = $i + 1;
                break;
            }
        }
        $this->assertNotNull($netoRow, 'no se encontró la fila de Neto proyectado');
        $this->assertMoneyCell($sheet, "D{$netoRow}", $neto);
    }

    /**
     * AC de SCRUM-939 — "los negativos (notas de crédito) quedan numéricos": Salida es el único
     * lugar de estos 3 reportes donde el monto se escribe en negativo (`-1 * monto`, ver
     * `CashPositionExportService::proyectadoExcel()`) — una nota de crédito pendiente es una salida
     * proyectada real (mismo fixture que `test_projected_salidas_incluye_nota_credito_...`).
     */
    public function test_939_excel_proyectado_escribe_salida_de_nota_de_credito_como_celda_numerica_negativa(): void
    {
        $userId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $invoice = AdminContInvoice::create(['numero' => 'F-9202', 'order_id' => 920101, 'cliente' => 'Fam. Herrera 939', 'monto' => 1000, 'saldo_aplicado' => 800]);
        AdminContCreditNote::create([
            'numero' => 'NC-9002', 'master_client_id' => 1, 'cliente' => 'Fam. Herrera 939',
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $invoice->id,
            'monto' => 350, 'subtotal' => 350, 'itbms' => 0, 'motivo' => 'Test',
            'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
            'registrado_por' => $userId, 'fecha' => now()->subDays(5)->toDateString(),
        ]);
        $token = $this->loginAsFelix();

        // 350 - (1000-800) saldo pendiente = 150 en la salida real, -150.0 en el Excel.
        $salidaMonto = (float) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200)
            ->json('salidas.0.monto');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/cash-position/export?view=proyectado&window=30&format=excel')
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $rows = $sheet->toArray();

        $salidaRow = null;
        foreach ($rows as $i => $row) {
            if (($row[0] ?? null) === 'Salida') {
                $salidaRow = $i + 1;
                break;
            }
        }
        $this->assertNotNull($salidaRow, 'no se encontró la fila de Salida');
        $this->assertMoneyCell($sheet, "D{$salidaRow}", -$salidaMonto);
        $this->assertLessThan(0, (float) $sheet->getCell("D{$salidaRow}")->getValue(), 'la salida debe quedar numérica y en negativo, no solo con formato de moneda');
    }

    // ---------------------------------------------------------------- REQ-524 daily-count ----

    public function test_daily_count_rechaza_a_management(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(403);
    }

    public function test_daily_count_arma_cobros_confirmados_hoy_por_metodo_inmediato_y_diferido(): void
    {
        AdminContPayment::create([
            'numero_recibo' => 'REC-9001', 'master_client_id' => 1, 'monto_recibido' => 300, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $diferido = AdminContPayment::create([
            'numero_recibo' => 'REC-9002', 'master_client_id' => 1, 'monto_recibido' => 200, 'saldo_favor_aplicado' => 50,
            'metodo_pago' => AdminContPayment::METODO_TRANSFERENCIA, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentConfirmation::create(['payment_id' => $diferido->id, 'numero_confirmacion' => 'CONF-9001', 'confirmed_at' => now()]);

        // esperando confirmación — nunca debe contar.
        AdminContPayment::create([
            'numero_recibo' => 'REC-9003', 'master_client_id' => 1, 'monto_recibido' => 999, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_YAPPY, 'estado' => AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION,
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $recibos = collect($response->json('cobros'))->pluck('concepto');
        $this->assertContains('Cobro REC-9001', $recibos);
        $this->assertContains('Cobro REC-9002', $recibos);
        $this->assertNotContains('Cobro REC-9003', $recibos);
        // 300 + 200 recibido, nunca los 50 de saldo_favor_aplicado (RN1 — solo cash nuevo del día).
        $this->assertEquals(500.0, $response->json('total_cobrado'));
    }

    public function test_daily_count_incluye_nota_credito_devuelta_hoy_con_el_monto_del_bank_movement(): void
    {
        $userId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $invoice = AdminContInvoice::create(['numero' => 'F-9106', 'order_id' => 910105, 'cliente' => 'Fam. Herrera', 'monto' => 1000, 'saldo_aplicado' => 700]);

        $note = AdminContCreditNote::create([
            'numero' => 'NC-9002', 'master_client_id' => 1, 'cliente' => 'Fam. Herrera',
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $invoice->id,
            'monto' => 500, 'subtotal' => 500, 'itbms' => 0, 'motivo' => 'Test',
            'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO, 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $userId, 'fecha' => now()->toDateString(),
        ]);
        AdminContBankMovement::create([
            'bank_account_id' => null, 'fecha' => now()->toDateString(), 'tipo' => AdminContBankMovement::TIPO_DEVOLUCION,
            'concepto' => "Excedente Nota de Crédito {$note->numero}", 'monto' => 500,
            'source_type' => AdminContBankMovement::SOURCE_CREDIT_NOTE, 'source_id' => $note->id,
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertEquals(500.0, $response->json('total_notas_credito'));
        $this->assertSame('Devolución de dinero — Fam. Herrera', $response->json('notas_credito.0.concepto'));
    }

    /**
     * SCRUM-879 (rebote PM Review 2026-09-10) — Gerencia reportó que una comisión ya pagada
     * aparecía en Real 30/90d (`bank_movements`) pero no en "Real (histórico)" + "Hoy", que en
     * realidad es el Arqueo del día (`DailyCashCountService`, no `CashPositionService::real()`).
     * Cubre también el pago sin cuenta asignada (REQ-534) — igual debe contar como salida real.
     */
    public function test_daily_count_incluye_comision_pagada_hoy_como_salida_real(): void
    {
        AdminContBankMovement::create([
            'bank_account_id' => null, 'fecha' => now()->toDateString(), 'tipo' => AdminContBankMovement::TIPO_COMISION,
            'concepto' => 'Comisión arquitecto Arquitecto 12 — PED-2026-0070', 'monto' => 18.10,
            'source_type' => AdminContBankMovement::SOURCE_ARCHITECT_COMMISSION, 'source_id' => 1,
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertEquals(18.10, $response->json('total_comisiones'));
        $this->assertSame('Comisión arquitecto Arquitecto 12 — PED-2026-0070', $response->json('comisiones.0.concepto'));
        $this->assertEquals(-18.10, $response->json('total_neto'));
    }

    public function test_daily_count_persiste_observacion_por_fila_y_observacion_general(): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-9004', 'master_client_id' => 1, 'monto_recibido' => 300, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)
            ->putJson('/api/admin-contab/cash-position/daily-count/entries', [
                'movement_type' => 'payment', 'movement_id' => $payment->id, 'observacion' => 'Contado en caja',
            ])
            ->assertStatus(204);

        $this->withHeaders($headers)
            ->putJson('/api/admin-contab/cash-position/daily-count/general-observation', ['observacion' => 'Sábado, poco movimiento'])
            ->assertStatus(204);

        $response = $this->withHeaders($headers)
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertSame('Contado en caja', $response->json('cobros.0.observacion'));
        $this->assertSame('Sábado, poco movimiento', $response->json('observacion_general'));
    }

    // ---------------------------------------------------------------- Batch 19 (SCRUM-602→606, REQ-525→529) ----

    public function test_daily_count_incluye_retencion_pendiente_de_constancia(): void
    {
        AdminContPayment::create([
            'numero_recibo' => 'REC-9101', 'master_client_id' => 1, 'monto_recibido' => 3450, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_RETENCION_IMPUESTOS, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
            'numero_documento_retencion' => 'Retención 2% ISR',
        ]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('retenciones'));
        $this->assertSame('Retención 2% ISR', $response->json('retenciones.0.motivo'));
        $this->assertNull($response->json('retenciones.0.constancia'));
    }

    public function test_close_pasa_a_pendiente_de_aprobacion_y_asigna_numero(): void
    {
        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $response = $this->withHeaders($headers)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);

        $this->assertSame('pendiente_aprobacion', $response->json('estado'));
        $this->assertSame(1, $response->json('numero'));
        $this->assertNotNull($response->json('cerrado_at'));
    }

    public function test_close_rechaza_doble_cierre(): void
    {
        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);
        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(409);
    }

    /**
     * Pre-QA SCRUM-878/879 (2026-09-10) — hallazgo CRÍTICO confirmado en vivo en dev: un
     * `bank_movement` de comisión creado DESPUÉS del cierre, pero fechado el mismo día del arqueo,
     * cambiaba en silencio el `total_neto` que Contabilidad ya había cerrado (y que Gerencia aprobaría sin
     * saberlo). El fix congela el total en `close()` — este test reproduce exactamente el
     * escenario real (arqueo #1, 2026-08-25) para que no vuelva a pasar.
     */
    public function test_close_congela_el_total_neto_contra_un_movimiento_retroactivo_posterior(): void
    {
        AdminContPayment::create([
            'numero_recibo' => 'REC-9010', 'master_client_id' => 1, 'monto_recibido' => 1000, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);
        $closed = $this->withHeaders($headers)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);

        $this->assertEquals(1000.0, $closed->json('total_neto'));
        $id = $closed->json('id');

        // Simula el backfill retroactivo: comisión fechada el mismo día del arqueo YA CERRADO,
        // pero creada (created_at) recién ahora, después del cierre. La request HTTP de arriba deja
        // el contexto de tenant limpio al terminar — hay que re-establecerlo antes de escribir acá.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContBankMovement::create([
            'bank_account_id' => null, 'fecha' => now()->toDateString(), 'tipo' => AdminContBankMovement::TIPO_COMISION,
            'concepto' => 'Comisión arquitecto Arquitecto 12 — PED-2026-0070', 'monto' => 845.81,
            'source_type' => AdminContBankMovement::SOURCE_ARCHITECT_COMMISSION, 'source_id' => 1,
        ]);

        $detail = $this->withHeaders($headers)
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);
        $this->assertEquals(1000.0, $detail->json('total_neto'), 'el total congelado no debe incluir la comisión retroactiva');
        $this->assertEmpty($detail->json('comisiones'), 'la comisión retroactiva tampoco debe aparecer en el detalle de movimientos del snapshot');

        $history = $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/history')->assertStatus(200);
        $this->assertEquals(1000.0, $history->json('data.0.total_neto'), 'el listado del historial debe leer el mismo total congelado');
    }

    public function test_editar_observacion_de_arqueo_ya_cerrado_es_rechazado(): void
    {
        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $this->withHeaders($headers)
            ->putJson('/api/admin-contab/cash-position/daily-count/general-observation', ['observacion' => 'Tarde'])
            ->assertStatus(422);
    }

    public function test_bloqueo_secuencial_el_arqueo_activo_es_el_atrasado_no_hoy(): void
    {
        // arqueo de ayer, sin cerrar (fila creada a mano simulando que quedó atrasado).
        AdminContDailyCashCount::create(['fecha' => now()->subDay()->toDateString()]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertSame(now()->subDay()->toDateString(), $response->json('fecha'));
        $this->assertTrue($response->json('es_atrasado'));
    }

    public function test_bloqueo_secuencial_avanza_a_hoy_tras_cerrar_el_atrasado(): void
    {
        AdminContDailyCashCount::create(['fecha' => now()->subDay()->toDateString()]);

        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/daily-count')->assertStatus(200);

        $this->assertSame(now()->toDateString(), $response->json('fecha'));
        $this->assertFalse($response->json('es_atrasado'));
    }

    /**
     * SCRUM-867 — hallazgo del cliente: con el arqueo de un día anterior sin cerrar, el sistema
     * mostraba directamente el de hoy sin ningún aviso. Reproduce el escenario real reportado: un
     * día SIN NINGUNA fila (cero movimientos ese día — nadie tocó Arqueo de Caja, así que
     * `activeRecordForWrite()` nunca corrió `firstOrCreate()` para esa fecha), en medio de
     * historial real ya existente (no en el piso de la tabla, para descartar que el fix dependa
     * de tener el gap justo al principio).
     */
    public function test_bloqueo_secuencial_detecta_un_dia_atrasado_sin_ninguna_fila_creada(): void
    {
        // Historial real: hace 3 y 2 días, ambos ya cerrados. Hace 1 día — CERO movimiento, sin
        // fila (el escenario real reportado: 7 sept nunca tuvo ninguna acción registrada).
        AdminContDailyCashCount::create(['fecha' => now()->subDays(3)->toDateString(), 'estado' => AdminContDailyCashCount::ESTADO_APROBADO]);
        AdminContDailyCashCount::create(['fecha' => now()->subDays(2)->toDateString(), 'estado' => AdminContDailyCashCount::ESTADO_APROBADO]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertSame(now()->subDay()->toDateString(), $response->json('fecha'));
        $this->assertTrue($response->json('es_atrasado'));
    }

    /**
     * SCRUM-867 — LIMITACIÓN CONOCIDA, no cubierta por este fix (documentada a propósito, no es
     * un "debería pasar"): si alguien setea `arqueo_tracking_start_date` a una fecha posterior a
     * un gap real, ese gap queda invisible para siempre — el piso explícito sigue pisando
     * cualquier otra señal. Hoy no existe ninguna pantalla para editar este campo (solo API
     * directa), así que el riesgo real es bajo, pero queda señalado para Arquitecto/Luis: si en
     * algún momento se agrega una UI para este campo, este mismo caso vuelve a ser alcanzable por
     * error humano y merece su propio fix (ej. validar que el nuevo valor no sea posterior a
     * ningún día con actividad real sin arquear).
     */
    public function test_bloqueo_secuencial_con_start_date_en_hoy_ignora_el_gap_anterior(): void
    {
        AdminContFiscalSettings::current()->update(['arqueo_tracking_start_date' => now()->toDateString()]);
        AdminContDailyCashCount::create(['fecha' => now()->subDays(3)->toDateString(), 'estado' => AdminContDailyCashCount::ESTADO_APROBADO]);
        AdminContDailyCashCount::create(['fecha' => now()->subDays(2)->toDateString(), 'estado' => AdminContDailyCashCount::ESTADO_APROBADO]);
        // hace 1 día: sin fila, cero movimiento — igual que el escenario reportado.

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertSame(now()->toDateString(), $response->json('fecha'));
        $this->assertFalse($response->json('es_atrasado'));
    }

    /**
     * SCRUM-867 — reproduce el escenario real reportado, confirmado por la evidencia del ticket
     * (N° de arqueo 1 en el modal de cierre, "Historial de arqueos cerrados" vacío): la tabla
     * `daily_cash_counts` está completamente vacía (primer uso real de la pantalla), pero YA hubo
     * un cobro confirmado ayer que nadie arqueó. Antes del fix, el piso caía directo en "hoy" sin
     * ningún aviso — exactamente el síntoma reportado.
     */
    public function test_bloqueo_secuencial_primer_uso_detecta_cobro_confirmado_el_dia_anterior(): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-8001', 'master_client_id' => 1, 'monto_recibido' => 150, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        // created_at no es mass-assignable en create() — se fuerza después, sin tocar updated_at.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.payments")->where('id', $payment->id)->update(['created_at' => now()->subDay()]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);

        $this->assertSame(now()->subDay()->toDateString(), $response->json('fecha'));
        $this->assertTrue($response->json('es_atrasado'));
    }

    public function test_history_lista_solo_cerrados_con_contador_de_pendientes(): void
    {
        $token = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/history')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('pendientes_aprobacion'));
        $this->assertSame('pendiente_aprobacion', $response->json('data.0.estado'));
    }

    public function test_history_rechaza_a_management_para_aprobar_pero_permite_ver(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $this->withHeaders($contabilidadHeaders)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->getJson('/api/admin-contab/cash-position/history')
            ->assertStatus(200);
    }

    public function test_approve_es_exclusivo_de_mark(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        // Contabilidad (lider_admin_contab) no es Gerencia — rechazado.
        $this->withHeaders($contabilidadHeaders)
            ->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])
            ->assertStatus(403);

        $markToken = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($markToken))
            ->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])
            ->assertStatus(200);

        $this->assertSame('aprobado', $response->json('estado'));
        $this->assertNotNull($response->json('aprobado_at'));
    }

    public function test_approve_rechaza_doble_aprobacion(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $markHeaders = $this->authHeaders($this->loginAsMark());
        $this->withHeaders($markHeaders)->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])->assertStatus(200);
        $this->withHeaders($markHeaders)->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])->assertStatus(409);
    }

    // ---------------------------------------------------------------- puede_aprobar (gap encontrado por Frontend Dev) ----

    public function test_history_detail_puede_aprobar_true_para_aprobador_cuando_esta_pendiente(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $response = $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);

        $this->assertTrue($response->json('puede_aprobar'));
    }

    public function test_history_detail_puede_aprobar_false_para_contabilidad(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $response = $this->withHeaders($contabilidadHeaders)
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);

        $this->assertFalse($response->json('puede_aprobar'));
    }

    public function test_history_detail_puede_aprobar_false_cuando_ya_esta_aprobado(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $markHeaders = $this->authHeaders($this->loginAsMark());
        $this->withHeaders($markHeaders)->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])->assertStatus(200);

        $response = $this->withHeaders($markHeaders)
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);

        $this->assertFalse($response->json('puede_aprobar'));
    }

    public function test_daily_count_export_devuelve_pdf(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count/export')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // SCRUM-878 — dompdf degrada en silencio (nunca 500), así que el test de arriba no agarraría un
    // rediseño mal armado. Éste renderiza el Blade directamente y verifica la estructura del mockup
    // (tabla única, recuadro de totales al final, bloque Conciliación) sobre el estándar de
    // encabezado/pie del sistema.
    public function test_scrum878_resumen_sigue_el_mockup_en_el_cuerpo_y_el_estandar_en_encabezado_pie(): void
    {
        // Efectivo = confirmación inmediata, cuenta por created_at (sin necesidad de un
        // AdminContPaymentConfirmation).
        AdminContPayment::create([
            'numero_recibo' => 'REC-878', 'master_client_id' => 1, 'monto_recibido' => 400, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $data = app(DailyCashCountService::class)->active();
        $viewData = app(DailyCashCountSummaryPdfService::class)->viewData($data);
        $html = view('admin-cont.daily-cash-count-summary-pdf', $viewData)->render();

        // Estándar del sistema, NO el mockup.
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('<script type="text/php">', $html);
        $this->assertStringContainsString('Pagina {PAGE_NUM} de {PAGE_COUNT}', $html);
        $this->assertStringNotContainsString('Documento generado por AtlanticERP', $html);
        $this->assertStringNotContainsString('FORMATO DE ARQUEO DE CAJA DIARIO', $html);

        // Bloque Resumen + Conciliación.
        $this->assertStringContainsString('Resumen', $html);
        $this->assertStringContainsString('Conciliación', $html);
        $this->assertStringContainsString('Retenciones con constancia:', $html);
        $this->assertMatchesRegularExpression('/Retenciones con constancia:<\/span>\s*0 de 0/', $html);

        // Una sola tabla de movimientos con las 4 columnas del mockup.
        $this->assertStringContainsString('Movimientos del día', $html);
        $this->assertStringContainsString('Concepto</th>', $html);
        $this->assertStringContainsString('Medio</th>', $html);
        $this->assertStringContainsString('Observaciones</th>', $html);
        $this->assertStringContainsString('Monto</th>', $html);
        $this->assertStringNotContainsString('Cobros del día', $html);
        $this->assertStringNotContainsString('Notas de crédito devueltas', $html);

        // Medio con etiqueta legible (METODO_LABELS), no el valor crudo en minúscula.
        $this->assertMatchesRegularExpression('/<td>Efectivo<\/td>/', $html);
        $this->assertStringNotContainsString('<td>efectivo</td>', $html);

        // Recuadro de totales DESPUÉS de la tabla.
        $posTabla = strpos($html, 'Movimientos del día');
        $posTotales = strpos($html, 'Total neto del día');
        $this->assertNotFalse($posTabla);
        $this->assertNotFalse($posTotales);
        $this->assertGreaterThan($posTabla, $posTotales);
        $this->assertStringContainsString('Notas crédito y devoluciones', $html);
    }

    public function test_history_detail_export_devuelve_pdf(): void
    {
        $contabilidadHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($contabilidadHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $this->withHeaders($contabilidadHeaders)
            ->getJson("/api/admin-contab/cash-position/history/{$id}/export")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ------------------------------------------------ SCRUM-934 indicadores del día ----

    private function makeInvoiceAt(float $monto, string $createdAt, bool $anulada = false): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-934-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente 934', 'monto' => $monto, 'saldo_aplicado' => 0,
            'anulada_at' => $anulada ? now() : null,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    /** Pedido raíz (o hermano de familia si se pasa `$familyId`) con la tarjeta de pipeline que porta el monto. */
    private function makePedido(float $amount, string $createdAt, ?int $familyId = null, ?PipelineCard $card = null): Order
    {
        if ($card === null) {
            $n = self::uniqueTestNumber();
            $master = MasterClient::create(['name' => "Cliente 934 {$n}"]);
            $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Sub 934 {$n}", 'tax_id' => "155-{$n}-1"]);
            $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto 934 {$n}"]);
            $card = PipelineCard::create([
                'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
                'sub_client_id' => $sub->id, 'owner_id' => (int) User::query()->value('id'), 'amount' => $amount, 'stage_changed_at' => now(),
            ]);
        }

        $order = Order::create([
            'order_number' => 'PED-934-'.self::uniqueTestNumber(), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_ASIGNADO,
            'customer_name' => 'Cliente 934', 'family_id' => $familyId,
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order;
    }

    private function getActiveDailyCount(string $token): TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/daily-count')
            ->assertStatus(200);
    }

    public function test_934_indicadores_suman_solo_lo_de_hoy_y_excluyen_anuladas_ayer_y_hermanas_de_familia(): void
    {
        $hoy = now()->toDateTimeString();
        $ayer = now()->subDay()->toDateTimeString();

        $this->makeInvoiceAt(100.00, $hoy);
        $this->makeInvoiceAt(250.50, $hoy);
        $this->makeInvoiceAt(999.00, $hoy, anulada: true); // anulada → nunca cuenta
        $this->makeInvoiceAt(777.00, $ayer);               // ayer → otro día

        $raiz = $this->makePedido(1000.00, $hoy);
        $this->makePedido(1000.00, $hoy, familyId: $raiz->id, card: $raiz->pipelineCard); // entrega hermana → NO doble cuenta
        $this->makePedido(500.00, $hoy);
        $this->makePedido(888.00, $ayer);                  // ayer → otro día

        $response = $this->getActiveDailyCount($this->loginAsFelix());

        $this->assertEquals(350.50, $response->json('total_facturado_dia'));
        $this->assertEquals(1500.00, $response->json('total_pasado_a_pedido_dia'));
        // Informativos: nunca entran al total neto de caja.
        $this->assertEquals(0.0, $response->json('total_neto'));
    }

    public function test_934_indicadores_parten_en_cero_si_solo_hubo_movimiento_otro_dia(): void
    {
        $ayer = now()->subDay()->toDateTimeString();
        $this->makeInvoiceAt(400.00, $ayer);
        $this->makePedido(600.00, $ayer);

        $response = $this->getActiveDailyCount($this->loginAsFelix());

        // El arqueo activo es el de HOY (no hay filas ni cobros previos) → contador de hoy en cero.
        // `assertIsNumeric` primero: `assertEquals(0.0, null)` pasaría igual con el campo AUSENTE
        // (Senior Review BAJO-1) y no probaría nada.
        $this->assertIsNumeric($response->json('total_facturado_dia'));
        $this->assertIsNumeric($response->json('total_pasado_a_pedido_dia'));
        $this->assertEqualsWithDelta(0.0, (float) $response->json('total_facturado_dia'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $response->json('total_pasado_a_pedido_dia'), 0.001);
    }

    public function test_934_indicadores_reflejan_el_estado_al_momento_de_generar_y_cambian_si_se_regenera(): void
    {
        $token = $this->loginAsFelix();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el login HTTP deja el tenant limpio
        $this->makeInvoiceAt(100.00, now()->toDateTimeString());
        $primero = $this->getActiveDailyCount($token);
        $this->assertEquals(100.00, $primero->json('total_facturado_dia'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->makeInvoiceAt(50.00, now()->toDateTimeString());
        $segundo = $this->getActiveDailyCount($token);
        $this->assertEquals(150.00, $segundo->json('total_facturado_dia'), 'un arqueo abierto regenerado más tarde acumula lo nuevo');
    }

    public function test_934_close_congela_los_indicadores_contra_una_factura_posterior(): void
    {
        $token = $this->loginAsFelix();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el login HTTP deja el tenant limpio
        $this->makeInvoiceAt(100.00, now()->toDateTimeString());
        $this->makePedido(300.00, now()->toDateTimeString());

        $closed = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $this->assertEquals(100.00, $closed->json('total_facturado_dia'));
        $this->assertEquals(300.00, $closed->json('total_pasado_a_pedido_dia'));
        $id = $closed->json('id');

        // Senior Review BAJO-2 — sin esto el test pasaría aunque el snapshot NO trajera los
        // indicadores: `totalsFor()` los reconstruiría a la hora del cierre y daría lo mismo.
        // Se afirma sobre el snapshot PERSISTIDO, que es lo que de verdad se congela.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $snapshot = AdminContDailyCashCount::findOrFail($id)->snapshot;
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('total_facturado_dia', $snapshot);
        $this->assertArrayHasKey('total_pasado_a_pedido_dia', $snapshot);
        $this->assertEqualsWithDelta(100.00, (float) $snapshot['total_facturado_dia'], 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $snapshot['total_pasado_a_pedido_dia'], 0.001);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->makeInvoiceAt(900.00, now()->addMinute()->toDateTimeString());
        $this->makePedido(900.00, now()->addMinute()->toDateTimeString());

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);
        $this->assertEquals(100.00, $detail->json('total_facturado_dia'));
        $this->assertEquals(300.00, $detail->json('total_pasado_a_pedido_dia'));
    }

    public function test_934_snapshot_legado_sin_indicadores_se_reconstruye_a_la_hora_del_cierre_no_en_vivo(): void
    {
        $cierre = now()->addMinutes(5);
        $this->makeInvoiceAt(100.00, now()->toDateTimeString());              // antes del cierre
        $this->makeInvoiceAt(900.00, now()->addMinutes(10)->toDateTimeString()); // después del cierre

        $record = AdminContDailyCashCount::create([
            'fecha' => now()->toDateString(), 'estado' => AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION,
        ]);
        $record->forceFill([
            'numero' => 1, 'cerrado_at' => $cierre,
            'snapshot' => [
                'fecha' => now()->toDateString(), 'cobros' => [], 'notas_credito' => [], 'comisiones' => [],
                'total_cobrado' => 0, 'total_notas_credito' => 0, 'total_comisiones' => 0, 'total_neto' => 0,
            ],
        ])->save();

        $detail = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/cash-position/history/{$record->id}")
            ->assertStatus(200);

        $this->assertEquals(100.00, $detail->json('total_facturado_dia'));
    }

    public function test_934_el_pdf_del_arqueo_muestra_los_indicadores_bajo_los_totales(): void
    {
        $this->makeInvoiceAt(350.50, now()->toDateTimeString());
        $this->makePedido(1500.00, now()->toDateTimeString());

        $data = $this->getActiveDailyCount($this->loginAsFelix())->json();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $html = view('admin-cont.daily-cash-count-summary-pdf', (new DailyCashCountSummaryPdfService)->viewData($data))->render();

        $this->assertStringContainsString('Total facturado en el día', $html);
        $this->assertStringContainsString('$350.50', $html);
        $this->assertStringContainsString('Total pasado a pedido en el día', $html);
        $this->assertStringContainsString('$1,500.00', $html);
        $this->assertGreaterThan(strpos($html, 'Total neto del día'), strpos($html, 'Total facturado en el día'), 'los indicadores van DESPUÉS de los totales');
    }

    // ── SCRUM-947 — per_page real (antes fijo en 20, sin selector) ─────────────────────────────

    public function test_history_pagina_20_por_defecto_y_respeta_per_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            AdminContDailyCashCount::create([
                'fecha' => now()->subDays($i + 1)->toDateString(), 'numero' => 100 + $i,
                'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => now()->subDays($i + 1),
                'snapshot' => ['fecha' => now()->subDays($i + 1)->toDateString(), 'cobros' => [], 'notas_credito' => [], 'comisiones' => [], 'total_cobrado' => 0.0, 'total_notas_credito' => 0.0, 'total_comisiones' => 0.0, 'total_neto' => 0.0, 'observacion_general' => null],
            ]);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/cash-position/history')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['data']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);

        $perPage10 = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/cash-position/history?per_page=10')
            ->assertStatus(200)->json();
        $this->assertCount(10, $perPage10['data']);
        $this->assertSame(10, $perPage10['meta']['per_page']);
    }
}
