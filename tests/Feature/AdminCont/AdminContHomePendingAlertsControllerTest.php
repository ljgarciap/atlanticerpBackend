<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Tests\Feature\FeatureTestCase;

/**
 * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435), Grupo 3 (SCRUM-510,
 * REQ-433) — panel "Pendientes".
 */
class AdminContHomePendingAlertsControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL    = 'conta@illuminations.com.pa';
    private const DESIGNER_EMAIL = 'designer@illuminations.test';
    private const URL            = '/api/admin-contab/home/pendientes';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function loginAsDesigner(): string
    {
        return $this->loginAs(self::DESIGNER_EMAIL, 'Password123!', 'designer');
    }

    private function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    /** Mismo esqueleto que AdminContHomeControllerTest::makeInvoice(). */
    private function makeInvoice(float $monto, float $saldoAplicado, string $createdAt): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente Pendientes', 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    /** Pedido comisionable interno, cobrado por completo, `stage_changed_at` configurable para
     *  controlar `mes_cierre` (siempre 1° del mes de esa fecha). */
    private function makeCommissionableOrder(int $ownerId, float $amount, CarbonImmutable $stageChangedAt): AdminContInvoice
    {
        $master  = MasterClient::create(['name' => 'Cliente Comisión Pend '.self::uniqueTestNumber()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Comisión Pend '.self::uniqueTestNumber(), 'tax_id' => '155-0000-8-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Comisión Pend '.self::uniqueTestNumber()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => $stageChangedAt,
        ]);
        $order = Order::create([
            'order_number' => 'PED-PEND-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        return AdminContInvoice::create([
            'numero' => 'F-PEND-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => 0,
        ]);
    }

    /** Proyecto referido por un arquitecto, cobrado por completo (vía `saldo_aplicado` directo,
     *  como en AdminContArchitectCommissionControllerTest::makeArchitectProject()), más un Payment
     *  confirmado real con `created_at` propio — `fecha_cobro_completo` en ArchitectCommissionService
     *  viene de `max(payments.created_at)`, no de `saldo_aplicado` directo, así que hace falta el
     *  Payment real para que la Regla 3 (externas) tenga una fecha de referencia. Perfil fiscal
     *  obligatorio: sin él, `ArchitectCommissionService::summary()` descarta el monto pendiente de
     *  ese arquitecto por "datos fiscales incompletos" (`enrichProject()`/`montoFor()` devuelven
     *  `total: null`), y esta regla nunca alertaría aunque la comisión sea real. */
    private function makeArchitectProjectWithPayment(Architect $architect, float $amount, string $paymentCreatedAt): void
    {
        ArchitectFiscalProfile::create([
            'architect_id' => $architect->id, 'empresa' => 'Estudio Pend', 'ruc' => '1-1-1',
            'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO,
        ]);

        $master  = MasterClient::create(['name' => 'Cliente Externa Pend '.self::uniqueTestNumber()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Externa Pend '.self::uniqueTestNumber(), 'tax_id' => '155-0000-7-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Externa Pend '.self::uniqueTestNumber()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userIdByEmail(self::FELIX_EMAIL), 'amount' => $amount,
            'stage_changed_at' => now(),
        ]);
        Quote::create([
            'owner_id' => $this->userIdByEmail(self::FELIX_EMAIL), 'sales_project_id' => $project->id,
            'architect_id' => $architect->id, 'status' => 'approved',
        ]);
        $order = Order::create([
            'order_number' => 'PED-ARQ-PEND-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-ARQ-PEND-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $amount,
        ]);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-ARQ-'.self::uniqueTestNumber(), 'master_client_id' => $master->id,
            'monto_recibido' => $amount, 'saldo_favor_aplicado' => 0, 'metodo_pago' => AdminContPayment::METODO_EFECTIVO,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $amount]);
        $payment->forceFill(['created_at' => $paymentCreatedAt])->save();
    }

    // ------------------------------------------------------------------- Gate de permisos ----

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(403);
    }

    public function test_sin_datos_devuelve_count_cero(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $response->assertJsonStructure(['count', 'items']);
        $this->assertEquals(0, $response->json('count'));
        $this->assertEquals([], $response->json('items'));
    }

    // ------------------------------------------------------------------- Regla 1 ----

    public function test_factura_vencida_sin_ningun_pago_genera_alerta_alta(): void
    {
        // dias_credito_factura default = 30 — creada hace 45 días => vencida hace 15, sin pago.
        $this->makeInvoice(1000, 0, now()->subDays(45)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(1, $response->json('count'));
        $this->assertEquals('factura_vencida_sin_pago', $response->json('items.0.tipo'));
        $this->assertEquals('alta', $response->json('items.0.severidad'));
        $this->assertEquals(1000.0, $response->json('items.0.monto'));
    }

    public function test_factura_no_vencida_no_genera_alerta(): void
    {
        $this->makeInvoice(1000, 0, now()->subDays(5)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(0, $response->json('count'));
    }

    /**
     * RN2 (SCRUM-510): "más de 0 días desde su fecha de vencimiento" — estrictamente > 0. Una
     * factura que vence HOY (dias_vencido === 0) todavía no genera la alerta, a diferencia del
     * umbral de REQ-428 ("Cuentas al día"), que sí trata "vence hoy" como mora.
     */
    public function test_factura_que_vence_hoy_no_genera_alerta_todavia(): void
    {
        $dias = \App\Modules\AdminCont\Models\AdminContFiscalSettings::current()->dias_credito_factura;
        $this->makeInvoice(1000, 0, now()->subDays($dias)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(0, $response->json('count'));
    }

    // ------------------------------------------------------------------- Regla 2 ----

    public function test_factura_vencida_con_pago_parcial_genera_alerta_media(): void
    {
        $this->makeInvoice(1000, 400, now()->subDays(45)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(1, $response->json('count'));
        $this->assertEquals('pago_parcial_incompleto', $response->json('items.0.tipo'));
        $this->assertEquals('media', $response->json('items.0.severidad'));
        $this->assertEquals(600.0, $response->json('items.0.monto'));
    }

    public function test_factura_vencida_totalmente_pagada_no_genera_ninguna_alerta(): void
    {
        $this->makeInvoice(1000, 1000, now()->subDays(45)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(0, $response->json('count'));
    }

    // ------------------------------------------------------------------- Regla 3 — internas ----

    public function test_comision_interna_pendiente_hace_mas_de_10_dias_genera_alerta(): void
    {
        $roleId   = Role::where('key', 'vendedor_disenador')->value('id');
        $vendedor = User::where('role_id', $roleId)->first();
        $this->assertNotNull($vendedor, 'debe existir al menos un vendedor_disenador real sembrado en testing');

        // Fixture ANTES de la request HTTP — mismo gotcha de Tenant::current() ya documentado en
        // AdminContHomeControllerTest::test_comisiones_internas_suma_todos_los_vendedores_sin_scope_de_actor().
        // mes_cierre = 1° del mes de stage_changed_at. Con "hoy" real (>10 del mes en la mayoría de
        // corridas) esto ya alertaría, pero se fuerza explícito con travelTo() para no depender del
        // día del mes en que corre la suite.
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $invoice = $this->makeCommissionableOrder((int) $vendedor->id, 5000.0, CarbonImmutable::parse('2026-06-01'));
        $invoice->forceFill(['saldo_aplicado' => 5000.0])->save();

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $comisionItems = collect($response->json('items'))->where('tipo', 'comision_pendiente');
        $this->assertCount(1, $comisionItems, 'debe generar exactamente 1 alerta de comisión interna pendiente');
        $this->assertEquals('media', $comisionItems->first()['severidad']);
        $this->assertEquals('2026-06-01', $comisionItems->first()['fecha_referencia']);
    }

    /** QA SCRUM-510 (2026-08-28) — Carbon 3 diffInDays() devuelve float; "hoy" con minutos/segundos
     *  reales (no medianoche exacta) reproducía "27.607690584572 días" en el texto de la alerta. */
    public function test_comision_interna_pendiente_muestra_dias_como_entero_sin_decimales(): void
    {
        $roleId   = Role::where('key', 'vendedor_disenador')->value('id');
        $vendedor = User::where('role_id', $roleId)->first();
        $this->assertNotNull($vendedor);

        $this->travelTo(CarbonImmutable::parse('2026-06-28 14:37:22'));
        $invoice = $this->makeCommissionableOrder((int) $vendedor->id, 5000.0, CarbonImmutable::parse('2026-06-01'));
        $invoice->forceFill(['saldo_aplicado' => 5000.0])->save();

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $comisionItems = collect($response->json('items'))->where('tipo', 'comision_pendiente');
        $this->assertCount(1, $comisionItems);
        $this->assertMatchesRegularExpression(
            '/Pendiente hace \d+ días/',
            $comisionItems->first()['detalle'],
            'el texto no debe mostrar decimales en los días de antigüedad',
        );
    }

    public function test_comision_interna_dentro_del_umbral_no_genera_alerta(): void
    {
        $roleId   = Role::where('key', 'vendedor_disenador')->value('id');
        $vendedor = User::where('role_id', $roleId)->first();
        $this->assertNotNull($vendedor);

        // mes_cierre = 2026-06-01, "hoy" = 2026-06-08 => 7 días pendiente, bajo el umbral de 10.
        $this->travelTo(CarbonImmutable::parse('2026-06-08 12:00:00'));
        $invoice = $this->makeCommissionableOrder((int) $vendedor->id, 5000.0, CarbonImmutable::parse('2026-06-01'));
        $invoice->forceFill(['saldo_aplicado' => 5000.0])->save();

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $comisionItems = collect($response->json('items'))->where('tipo', 'comision_pendiente');
        $this->assertCount(0, $comisionItems);
    }

    // ------------------------------------------------------------------- Regla 3 — externas ----

    public function test_comision_externa_pendiente_hace_mas_de_10_dias_genera_alerta(): void
    {
        $architect = Architect::create(['name' => 'Arq. Pendiente '.uniqid(), 'email' => uniqid().'@arq.test']);

        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeArchitectProjectWithPayment($architect, 3000.0, '2026-06-01 10:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $comisionItems = collect($response->json('items'))->where('tipo', 'comision_pendiente');
        $this->assertGreaterThanOrEqual(1, $comisionItems->count());
        $externa = $comisionItems->first(fn (array $i) => str_contains($i['titulo'], $architect->name));
        $this->assertNotNull($externa, 'debe incluir una alerta con el nombre del arquitecto');
        $this->assertEquals('2026-06-01', $externa['fecha_referencia']);
    }
}
