<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentConfirmation;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** Batch 18 (SCRUM-597→601, REQ-520→524) — ver ADR-SCRUM597-601-batch18-arqueo-caja.md. */
class AdminContCashPositionControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL  = 'conta@illuminations.com.pa';
    private const YANETH_EMAIL = 'asistente@illuminations.com.pa';
    private const MARK_EMAIL   = 'mbekhar@illuminations.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::YANETH_EMAIL, self::YANETH_EMAIL, 'asistente_administrativa');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    // ---------------------------------------------------------------- REQ-520 header ----

    public function test_header_suma_bancos_activos_mas_caja_menuda_e_ignora_cuenta_inactiva(): void
    {
        $activa   = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);
        $inactiva = AdminContBankAccount::create(['banco' => 'Global Bank', 'ultimos_4_digitos' => '1111', 'activa' => false]);

        AdminContBankMovement::create(['bank_account_id' => $activa->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro', 'monto' => 500]);
        AdminContBankMovement::create(['bank_account_id' => $activa->id, 'fecha' => now(), 'tipo' => 'comision', 'concepto' => 'Comisión', 'monto' => 100]);
        AdminContBankMovement::create(['bank_account_id' => $inactiva->id, 'fecha' => now(), 'tipo' => 'cobro', 'concepto' => 'Cobro cuenta inactiva', 'monto' => 9999]);

        AdminContFiscalSettings::current()->update(['petty_cash_balance' => 340]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position')
            ->assertStatus(200);

        $response->assertJson([
            'saldo_bancos'         => 400.0,
            'saldo_caja_menuda'    => 340.0,
            'saldo_disponible_hoy' => 740.0,
        ]);
    }

    // ---------------------------------------------------------------- REQ-521/522 projected, gates ----

    /**
     * Aclarado por Luis 2026-08-25 (Pre-QA Batch 18): REQ-521 le veda a Yaneth el detalle línea
     * por línea, no los 3 totales agregados del encabezado (esos son de REQ-520, que sí la
     * incluye) — antes esta prueba esperaba 403 acá, ahora espera 200 con entradas/salidas vacíos
     * pero los totales calculados sobre el dato completo.
     */
    public function test_projected_permite_a_yaneth_pero_sin_el_detalle_linea_por_linea(): void
    {
        AdminContInvoice::create(['numero' => 'F-9199', 'order_id' => 919900, 'cliente' => 'Cliente Yaneth', 'monto' => 750]);

        $token = $this->loginAsYaneth();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $response->assertJson(['entradas' => [], 'salidas' => []]);
        $this->assertEquals(750.0, $response->json('total_entradas'));
    }

    public function test_real_rechaza_a_yaneth(): void
    {
        $token = $this->loginAsYaneth();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/real?window=30')
            ->assertStatus(403);
    }

    public function test_projected_incluye_factura_atrasada_y_proxima_ambas_con_saldo_pendiente(): void
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;

        $proxima  = AdminContInvoice::create(['numero' => 'F-9101', 'order_id' => 910100, 'cliente' => 'Cliente Próximo', 'monto' => 1000]);
        $atrasada = AdminContInvoice::create(['numero' => 'F-9102', 'order_id' => 910101, 'cliente' => 'Cliente Atrasado', 'monto' => 2000]);

        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
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
        $userId  = $this->userIdByEmail(self::FELIX_EMAIL);
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
        $account = AdminContBankAccount::create(['banco' => 'Banistmo', 'ultimos_4_digitos' => '9034']);

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
     * acá no hay versión "solo totales" para Yaneth, así que la ruta se rechaza directo.
     */
    public function test_export_rechaza_a_yaneth(): void
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
        $userId  = $this->userIdByEmail(self::FELIX_EMAIL);
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

    public function test_daily_count_persiste_observacion_por_fila_y_observacion_general(): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-9004', 'master_client_id' => 1, 'monto_recibido' => 300, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);

        $token   = $this->loginAsFelix();
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
        $token   = $this->loginAsFelix();
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
        $token   = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);
        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(409);
    }

    public function test_editar_observacion_de_arqueo_ya_cerrado_es_rechazado(): void
    {
        $token   = $this->loginAsFelix();
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

        $token   = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/daily-count')->assertStatus(200);

        $this->assertSame(now()->toDateString(), $response->json('fecha'));
        $this->assertFalse($response->json('es_atrasado'));
    }

    public function test_history_lista_solo_cerrados_con_contador_de_pendientes(): void
    {
        $token   = $this->loginAsFelix();
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/admin-contab/cash-position/history')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('pendientes_aprobacion'));
        $this->assertSame('pendiente_aprobacion', $response->json('data.0.estado'));
    }

    public function test_history_rechaza_a_management_para_aprobar_pero_permite_ver(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $this->withHeaders($felixHeaders)->postJson('/api/admin-contab/cash-position/daily-count/close', [])->assertStatus(200);

        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->getJson('/api/admin-contab/cash-position/history')
            ->assertStatus(200);
    }

    public function test_approve_es_exclusivo_de_mark(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        // Felix (lider_admin_contab) no es Mark — rechazado.
        $this->withHeaders($felixHeaders)
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
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $markHeaders = $this->authHeaders($this->loginAsMark());
        $this->withHeaders($markHeaders)->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])->assertStatus(200);
        $this->withHeaders($markHeaders)->postJson("/api/admin-contab/cash-position/history/{$id}/approve", [])->assertStatus(409);
    }

    // ---------------------------------------------------------------- puede_aprobar (gap encontrado por Frontend Dev) ----

    public function test_history_detail_puede_aprobar_true_para_mark_cuando_esta_pendiente(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $response = $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);

        $this->assertTrue($response->json('puede_aprobar'));
    }

    public function test_history_detail_puede_aprobar_false_para_felix(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $response = $this->withHeaders($felixHeaders)
            ->getJson("/api/admin-contab/cash-position/history/{$id}")
            ->assertStatus(200);

        $this->assertFalse($response->json('puede_aprobar'));
    }

    public function test_history_detail_puede_aprobar_false_cuando_ya_esta_aprobado(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
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

    public function test_history_detail_export_devuelve_pdf(): void
    {
        $felixHeaders = $this->authHeaders($this->loginAsFelix());
        $close = $this->withHeaders($felixHeaders)
            ->postJson('/api/admin-contab/cash-position/daily-count/close', [])
            ->assertStatus(200);
        $id = $close->json('id');

        $this->withHeaders($felixHeaders)
            ->getJson("/api/admin-contab/cash-position/history/{$id}/export")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
