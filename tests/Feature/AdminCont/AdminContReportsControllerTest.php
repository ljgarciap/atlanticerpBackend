<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\ArchitectCommission;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/** Batch 22 de Admin&Cont (SCRUM-643→647, REQ-566→570) + Batch 23 (SCRUM-648/649, REQ-571/572) —
 *  home de Reportes. */
class AdminContReportsControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    private const DESIGNER_EMAIL = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    private function loginAsDesigner(): string
    {
        return $this->loginAs(self::DESIGNER_EMAIL, 'Password123!', 'designer');
    }

    private function makeInvoice(float $monto, float $saldoAplicado, string $createdAt): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente Reportes', 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    private function makeConfirmedPayment(AdminContInvoice $invoice, float $montoAplicado, string $createdAt): AdminContPayment
    {
        $masterClient = MasterClient::create(['name' => 'Cliente Reportes '.self::uniqueTestNumber()]);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClient->id,
            'monto_recibido' => $montoAplicado, 'saldo_favor_aplicado' => 0, 'metodo_pago' => AdminContPayment::METODO_EFECTIVO,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $montoAplicado]);
        $payment->forceFill(['created_at' => $createdAt])->save();

        // `agingBuckets()`/"pendiente" leen `saldo_aplicado` de la factura — un cobro real (vía
        // PaymentService) lo actualiza; acá se replica a mano porque el fixture crea el pivote
        // directo, sin pasar por ese servicio.
        $invoice->increment('saldo_aplicado', $montoAplicado);

        return $payment;
    }

    private function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    /** Mismo esqueleto que AdminContInternalCommissionControllerTest::makeCommissionableOrder(). */
    private function makeCommissionableOrder(int $ownerId, float $amount, ?Carbon $mesCierre = null): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente Comisión Reportes '.self::uniqueTestNumber()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Comisión Reportes '.self::uniqueTestNumber(), 'tax_id' => '155-0000-9-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Comisión Reportes '.self::uniqueTestNumber()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => $mesCierre ?? Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-REP-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        return AdminContInvoice::create([
            'numero' => 'F-REP-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => 0,
        ]);
    }

    /** Mismo esqueleto que AdminContArchitectCommissionControllerTest::makeArchitectProject() +
     *  markCommissionPaid() — proyecto de arquitecto ya pagado, para poblar `fecha_pago`. */
    private function makeArchitectProjectPagado(float $amount, string $fechaPago): void
    {
        $architect = Architect::create(['name' => 'Arq. Reportes '.self::uniqueTestNumber(), 'email' => self::uniqueTestNumber().'@arq.test']);
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio Reportes', 'ruc' => '1-1-'.self::uniqueTestNumber(), 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);

        $master = MasterClient::create(['name' => 'Cliente Externa Reportes '.self::uniqueTestNumber()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Externa Reportes '.self::uniqueTestNumber(), 'tax_id' => '155-0000-8-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Externa Reportes '.self::uniqueTestNumber()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'amount' => $amount,
            'stage_changed_at' => Carbon::now(),
        ]);
        Quote::create([
            'owner_id' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'sales_project_id' => $project->id,
            'architect_id' => $architect->id, 'status' => 'approved',
        ]);
        $order = Order::create([
            'order_number' => 'PED-ARQ-REP-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);
        AdminContInvoice::create([
            'numero' => 'F-ARQ-REP-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $amount,
        ]);

        ArchitectCommission::create(['pipeline_card_id' => $card->id, 'architect_id' => $architect->id, 'fecha_pago' => $fechaPago]);
    }

    private function makeCreditNote(string $tipo, ?string $subtipo, float $monto, string $estado, string $fecha): AdminContCreditNote
    {
        $invoice = $this->makeInvoice($monto, 0, $fecha);
        $masterClient = MasterClient::create(['name' => 'Cliente NC Reportes '.self::uniqueTestNumber()]);

        return AdminContCreditNote::create([
            'numero' => 'NC-REP-'.random_int(1000, 999999), 'master_client_id' => $masterClient->id,
            'cliente' => $invoice->cliente, 'tipo' => $tipo, 'subtipo_anulacion' => $subtipo, 'factura_origen_id' => $invoice->id,
            'monto' => $monto, 'subtotal' => $monto, 'itbms' => 0, 'motivo' => 'Motivo de prueba Reportes',
            'estado' => $estado, 'registrado_por' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'fecha' => $fecha,
        ]);
    }

    // ------------------------------------------------------------------- Gate de permisos ----

    public function test_vendedor_disenador_recibe_403_en_los_4_endpoints_de_reportes(): void
    {
        $token = $this->loginAsDesigner();

        foreach (['/api/admin-contab/reports/felix-commission', '/api/admin-contab/reports/cartera',
            '/api/admin-contab/reports/ventas', '/api/admin-contab/reports/flujo-caja'] as $url) {
            $this->withHeaders($this->authHeaders($token))->getJson($url)->assertStatus(403);
        }
    }

    public function test_asistente_puede_ver_cartera_ventas_y_flujo_caja(): void
    {
        $token = $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');

        foreach (['/api/admin-contab/reports/cartera',
            '/api/admin-contab/reports/ventas', '/api/admin-contab/reports/flujo-caja'] as $url) {
            $this->withHeaders($this->authHeaders($token))->getJson($url)->assertStatus(200);
        }
    }

    /**
     * Corrección SCRUM-881 (Gerencia, 2026-09-09) — "Comisión por gestión de cartera +90 días —
     * Contabilidad" debe verse SOLO para Contabilidad y Gerencia, no para Asistente Administrativa. Antes de esta corrección,
     * Asistente Administrativa SÍ veía este endpoint junto con el resto de Reportes (matriz de permisos original) —
     * `test_asistente_puede_ver_los_4_endpoints_de_reportes` cubría exactamente lo contrario y se
     * divide acá.
     */
    public function test_asistente_recibe_403_en_contabilidad_commission(): void
    {
        $token = $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------- REQ-569 ventas/periodo ----

    public function test_periodo_invalido_devuelve_422_en_ventas_y_flujo_caja(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas?periodo=semana')
            ->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/flujo-caja?periodo=semana')
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------- REQ-567 comisión Contabilidad ----

    public function test_contabilidad_commission_tramo_1_menos_de_15k(): void
    {
        $invoice = $this->makeInvoice(8400, 0, now()->subDays(140)->toDateTimeString());
        $this->makeConfirmedPayment($invoice, 8400, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(200);

        $response->assertJson(['cobrado_mes' => 8400.0, 'porcentaje' => 1.0, 'comision' => 84.0]);
        $this->assertStringContainsString('Menos de', (string) $response->json('rango_actual'));
    }

    public function test_contabilidad_commission_tramo_2_entre_15k_y_20k(): void
    {
        $invoice = $this->makeInvoice(17000, 0, now()->subDays(140)->toDateTimeString());
        $this->makeConfirmedPayment($invoice, 17000, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(200);

        $response->assertJson(['cobrado_mes' => 17000.0, 'porcentaje' => 1.5, 'comision' => 255.0]);
    }

    public function test_contabilidad_commission_tramo_3_mas_de_20k(): void
    {
        $invoice = $this->makeInvoice(25000, 0, now()->subDays(140)->toDateTimeString());
        $this->makeConfirmedPayment($invoice, 25000, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(200);

        $response->assertJson(['cobrado_mes' => 25000.0, 'porcentaje' => 2.0, 'comision' => 500.0]);
    }

    public function test_contabilidad_commission_expone_los_3_tramos_con_el_actual_marcado(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/felix-commission')
            ->assertStatus(200);

        $tiers = $response->json('tiers');
        $this->assertCount(3, $tiers);
        $actuales = array_filter($tiers, fn (array $t) => $t['es_actual'] === true);
        $this->assertCount(1, $actuales, 'exactamente 1 tramo debe quedar marcado como actual');
    }

    // ------------------------------------------------------------------- REQ-568 cartera ----

    public function test_cobrado_90_dias_solo_cuenta_pagos_de_facturas_vencidas_hace_mas_de_90_dias(): void
    {
        // dias_credito_factura por defecto = 30 (ver migración add_dias_credito_factura...).
        // Vencida hace 110 días al momento del pago (140 - 30 días de crédito) → SÍ cuenta.
        $vencida = $this->makeInvoice(500, 0, now()->subDays(140)->toDateTimeString());
        $this->makeConfirmedPayment($vencida, 500, now()->toDateTimeString());

        // Vencida hace solo 20 días al momento del pago (50 - 30) → NO cuenta.
        $reciente = $this->makeInvoice(300, 0, now()->subDays(50)->toDateTimeString());
        $this->makeConfirmedPayment($reciente, 300, now()->toDateTimeString());

        // Factura +90 días vencida, sin pagar (outstanding) → cuenta como "pendiente", no "cobrado".
        $pendiente = $this->makeInvoice(700, 0, now()->subDays(140)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/cartera')
            ->assertStatus(200);

        $this->assertEquals(500.0, $response->json('cobrado_90.cobrado_mes'));
        $this->assertEquals(700.0, $response->json('cobrado_90.pendiente'));
        $this->assertNotNull($pendiente->id);
    }

    // ------------------------------------------------------------------- REQ-569 ventas ----

    public function test_ventas_hoy_devuelve_total_del_dia_y_promedio_habil_del_mes_anterior(): void
    {
        $this->makeInvoice(1500, 0, now()->toDateTimeString());
        $this->makeInvoice(1600, 0, now()->toDateTimeString());

        $mesAnterior = now()->subMonthNoOverflow()->startOfMonth()->addDays(2);
        $this->makeInvoice(6200, 0, $mesAnterior->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas?periodo=hoy')
            ->assertStatus(200);

        $response->assertJson(['tipo' => 'hoy', 'hoy' => 3100.0]);
        $this->assertGreaterThan(0, $response->json('promedio_diario_mes_anterior'));
    }

    public function test_ventas_periodo_3m_devuelve_serie_mensual_terminando_en_mes_actual(): void
    {
        $this->makeInvoice(2000, 0, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas?periodo=3m')
            ->assertStatus(200);

        $response->assertJson(['tipo' => 'meses']);
        $meses = $response->json('meses');
        $this->assertCount(3, $meses);
        $this->assertEquals(now()->year, $meses[2]['anio']);
        $this->assertGreaterThanOrEqual(2000.0, $meses[2]['total']);
    }

    // ------------------------------------------------------------------- REQ-570 flujo de caja ----

    public function test_flujo_caja_saldo_proyectado_30_dias_es_saldo_hoy_mas_neto_proyectado(): void
    {
        $cuenta = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $cuenta->id, 'fecha' => now(), 'tipo' => AdminContBankMovement::TIPO_COBRO, 'concepto' => 'Cobro', 'monto' => 1000]);

        $token = $this->loginAsFelix();
        $flujo = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/flujo-caja?periodo=hoy')
            ->assertStatus(200);
        $header = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position')
            ->assertStatus(200);
        $projected = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/cash-position/projected?window=30')
            ->assertStatus(200);

        $esperado = round($header->json('saldo_disponible_hoy') + $projected->json('neto'), 2);
        $this->assertEquals($esperado, $flujo->json('saldo_proyectado_30_dias'));
        $this->assertEquals($header->json('saldo_disponible_hoy'), $flujo->json('saldo_disponible_hoy'));
    }

    public function test_flujo_caja_hoy_calcula_neto_de_movimientos_bancarios_del_dia(): void
    {
        $cuenta = AdminContBankAccount::create(['banco' => 'Banco Demo B', 'ultimos_4_digitos' => '9034']);
        AdminContBankMovement::create(['bank_account_id' => $cuenta->id, 'fecha' => now(), 'tipo' => AdminContBankMovement::TIPO_COBRO, 'concepto' => 'Cobro', 'monto' => 2200]);
        AdminContBankMovement::create(['bank_account_id' => $cuenta->id, 'fecha' => now(), 'tipo' => AdminContBankMovement::TIPO_COMISION, 'concepto' => 'Comisión', 'monto' => 1500]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/flujo-caja?periodo=hoy')
            ->assertStatus(200);

        $response->assertJson(['tipo' => 'hoy', 'hoy' => ['entradas' => 2200.0, 'salidas' => 1500.0, 'neto' => 700.0]]);
    }

    // ------------------------------------------------------------------- CRUD de tramos ----

    public function test_get_tiers_expone_los_3_tramos_seed(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/cartera/tiers')
            ->assertStatus(200);

        $this->assertCount(3, $response->json());
    }

    public function test_store_tier_rechaza_a_contabilidad_solo_aprobador_puede_escribir(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/commissions/cartera/tiers', ['monto_minimo' => 30000, 'monto_maximo' => null, 'porcentaje' => 3.0, 'orden' => 4])
            ->assertStatus(403);
    }

    public function test_aprobador_puede_crear_actualizar_y_borrar_un_tramo(): void
    {
        $token = $this->loginAsMark();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/commissions/cartera/tiers', ['monto_minimo' => 30000, 'monto_maximo' => null, 'porcentaje' => 3.0, 'orden' => 4])
            ->assertStatus(201);
        $id = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/cartera/tiers/{$id}", ['porcentaje' => 3.5])
            ->assertStatus(200)
            ->assertJson(['porcentaje' => 3.5]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin-contab/commissions/cartera/tiers/{$id}")
            ->assertStatus(204);
    }

    // ------------------------------------------------------------------- REQ-571 comisiones ----

    public function test_vendedor_disenador_recibe_403_en_comisiones_y_notas_credito(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/comisiones')->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/notas-credito')->assertStatus(403);
    }

    public function test_comisiones_periodo_invalido_devuelve_422(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/comisiones?periodo=semana')
            ->assertStatus(422);
    }

    public function test_comisiones_hoy_suma_internas_y_externas_ya_realizadas(): void
    {
        // Interna: pedido de $10,000 (tramo 1.5% seed) cobrado por completo hoy → $150.
        $invoice = $this->makeCommissionableOrder($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 10000.0);
        $this->makeConfirmedPayment($invoice, 10000.0, now()->toDateTimeString());

        // Externa: proyecto de $8,000, régimen exento (comisión 10% default) pagada hoy → $800.
        $this->makeArchitectProjectPagado(8000.0, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/comisiones?periodo=hoy')
            ->assertStatus(200);

        $response->assertJson(['tipo' => 'hoy']);
        $this->assertEquals(150.0, $response->json('hoy.internas'));
        $this->assertGreaterThan(0, $response->json('hoy.externas'));
    }

    public function test_comisiones_periodo_3m_devuelve_serie_mensual_terminando_en_mes_actual(): void
    {
        $invoice = $this->makeCommissionableOrder($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 10000.0);
        $this->makeConfirmedPayment($invoice, 10000.0, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/comisiones?periodo=3m')
            ->assertStatus(200);

        $response->assertJson(['tipo' => 'meses']);
        $meses = $response->json('meses');
        $this->assertCount(3, $meses);
        $this->assertEquals(now()->year, $meses[2]['anio']);
        $this->assertGreaterThan(0, $meses[2]['internas']);
    }

    // ------------------------------------------------------------------- REQ-572 notas crédito ----

    public function test_notas_credito_siempre_expone_los_4_motivos_en_orden(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/notas-credito')
            ->assertStatus(200);

        $motivos = array_column($response->json('motivos'), 'motivo');
        $this->assertSame([
            'Devolución de mercancía', 'Descuento comercial',
            'Anulación — Pedido cancelado', 'Anulación — Corrección de datos',
        ], $motivos);
    }

    public function test_notas_credito_agrupa_por_motivo_real_mes_actual(): void
    {
        $hoy = now()->toDateString();

        $this->makeCreditNote(AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, null, 2082, AdminContCreditNote::ESTADO_APLICADA, $hoy);
        $this->makeCreditNote(AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, null, 612, AdminContCreditNote::ESTADO_APLICADA, $hoy);
        $this->makeCreditNote(AdminContCreditNote::TIPO_ANULACION_COMPLETA, AdminContCreditNote::SUBTIPO_CANCELADO, 8250, AdminContCreditNote::ESTADO_APLICADA, $hoy);
        $this->makeCreditNote(AdminContCreditNote::TIPO_ANULACION_COMPLETA, AdminContCreditNote::SUBTIPO_CORRECCION, 4000, AdminContCreditNote::ESTADO_APLICADA, $hoy);
        // Pendiente de aprobación — nunca debe sumar (mismo criterio que summary()).
        $this->makeCreditNote(AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, null, 99999, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $hoy);
        // Mes anterior — nunca debe sumar (RN2, siempre mes en curso).
        $this->makeCreditNote(AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, null, 55555, AdminContCreditNote::ESTADO_APLICADA, now()->subMonthNoOverflow()->toDateString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/notas-credito')
            ->assertStatus(200);

        /** @var array<int, array{motivo: string, monto: float}> $motivosList */
        $motivosList = $response->json('motivos');
        /** @var array<string, float> $montoPorMotivo */
        $montoPorMotivo = array_column($motivosList, 'monto', 'motivo');
        $this->assertEquals(2082.0, $montoPorMotivo['Devolución de mercancía']);
        $this->assertEquals(612.0, $montoPorMotivo['Descuento comercial']);
        $this->assertEquals(8250.0, $montoPorMotivo['Anulación — Pedido cancelado']);
        $this->assertEquals(4000.0, $montoPorMotivo['Anulación — Corrección de datos']);
    }
}
