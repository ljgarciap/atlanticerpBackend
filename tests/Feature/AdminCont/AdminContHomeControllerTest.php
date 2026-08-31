<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435), Grupo 1 (SCRUM-504→508,
 * REQ-427→431) — panel "Resumen del mes".
 */
class AdminContHomeControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    private const YANETH_EMAIL = 'asistenteadministrativa@test.com';

    private const DESIGNER_EMAIL = 'designer@atlantic.test';

    private const URL = '/api/admin-contab/home/resumen-mes';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
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

    /** Mismo esqueleto que AdminContReportsControllerTest::makeInvoice(). */
    private function makeInvoice(float $monto, float $saldoAplicado, string $createdAt, bool $esIncobrable = false, bool $incobrablePendiente = false): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente Inicio', 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
            'es_incobrable' => $esIncobrable, 'incobrable_pendiente' => $incobrablePendiente,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    /** Mismo esqueleto que AdminContReportsControllerTest::makeConfirmedPayment(). */
    private function makeConfirmedPayment(AdminContInvoice $invoice, float $montoAplicado, string $createdAt): AdminContPayment
    {
        $masterClient = MasterClient::create(['name' => 'Cliente Inicio '.self::uniqueTestNumber()]);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClient->id,
            'monto_recibido' => $montoAplicado, 'saldo_favor_aplicado' => 0, 'metodo_pago' => AdminContPayment::METODO_EFECTIVO,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $montoAplicado]);
        $payment->forceFill(['created_at' => $createdAt])->save();
        $invoice->increment('saldo_aplicado', $montoAplicado);

        return $payment;
    }

    /** Mismo esqueleto que AdminContReportsControllerTest::makeCommissionableOrder() — pedido
     *  comisionable (interno) completamente cobrado, sin pagar todavía al vendedor (por_pagar). */
    private function makeCommissionableOrder(int $ownerId, float $amount): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente Comisión Inicio '.self::uniqueTestNumber()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Comisión Inicio '.self::uniqueTestNumber(), 'tax_id' => '155-0000-9-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Comisión Inicio '.self::uniqueTestNumber()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-INI-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        return AdminContInvoice::create([
            'numero' => 'F-INI-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => 0,
        ]);
    }

    // ------------------------------------------------------------------- Gate de permisos ----

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(403);
    }

    public function test_yaneth_puede_ver_el_resumen(): void
    {
        $token = $this->loginAs(self::YANETH_EMAIL, self::YANETH_EMAIL, 'asistente_administrativa');

        $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
    }

    public function test_expone_los_5_campos_del_contrato(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $response->assertJsonStructure([
            'total_cobrado_mes', 'mes_label',
            'cuentas_al_dia' => ['porcentaje', 'monto_al_dia', 'monto_con_mora'],
            'cartera_por_cobrar' => ['monto', 'monto_incobrable_excluido'],
            'ventas_de_ayer' => ['monto', 'cantidad', 'fecha'],
            'comisiones_por_pagar' => ['internas', 'externas'],
        ]);
    }

    // ------------------------------------------------------------------- REQ-427 total cobrado ----

    public function test_total_cobrado_mes_reusa_payment_service(): void
    {
        $invoice = $this->makeInvoice(4200, 0, now()->subDays(5)->toDateTimeString());
        $this->makeConfirmedPayment($invoice, 4200, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(4200.0, $response->json('total_cobrado_mes'));
    }

    // ------------------------------------------------------------------- REQ-428 cuentas al día ----

    public function test_factura_que_vence_hoy_cuenta_como_mora_no_al_dia(): void
    {
        // dias_credito_factura default = 30 (ver AdminContReportsControllerTest) — creada hace
        // exactamente 30 días => vence HOY => dias_vencido=0 => mora (RN2).
        $venceHoy = $this->makeInvoice(1000, 0, now()->subDays(30)->toDateTimeString());
        // Creada hace 10 días => vence en 20 días => todavía al día.
        $noVenceAun = $this->makeInvoice(600, 0, now()->subDays(10)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(600.0, $response->json('cuentas_al_dia.monto_al_dia'));
        $this->assertEquals(1000.0, $response->json('cuentas_al_dia.monto_con_mora'));
        $this->assertEqualsWithDelta(37.5, $response->json('cuentas_al_dia.porcentaje'), 0.01);
        $this->assertNotNull($venceHoy->id);
        $this->assertNotNull($noVenceAun->id);
    }

    // ------------------------------------------------------------------- REQ-429 cartera por cobrar ----

    public function test_cartera_incluye_incobrable_pendiente_pero_excluye_incobrable_aprobada(): void
    {
        $this->makeInvoice(500, 0, now()->toDateTimeString());
        // Propuesta de incobrable AÚN NO aprobada — `es_incobrable` sigue false, sigue siendo
        // cartera cobrable de verdad (RN: `decideUncollectible()` es el único que la saca).
        $this->makeInvoice(300, 0, now()->toDateTimeString(), esIncobrable: false, incobrablePendiente: true);
        // Incobrable YA aprobada — excluida de `monto`, contabilizada aparte.
        $this->makeInvoice(900, 0, now()->toDateTimeString(), esIncobrable: true);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(800.0, $response->json('cartera_por_cobrar.monto'));
        $this->assertEquals(900.0, $response->json('cartera_por_cobrar.monto_incobrable_excluido'));
    }

    // ------------------------------------------------------------------- REQ-430 ventas de ayer ----

    public function test_ventas_de_ayer_solo_cuenta_el_dia_calendario_anterior(): void
    {
        $this->makeInvoice(1200, 0, now()->subDay()->toDateTimeString());
        $this->makeInvoice(300, 0, now()->subDay()->toDateTimeString());
        $this->makeInvoice(5000, 0, now()->toDateTimeString());
        $this->makeInvoice(5000, 0, now()->subDays(2)->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(1500.0, $response->json('ventas_de_ayer.monto'));
        $this->assertEquals(2, $response->json('ventas_de_ayer.cantidad'));
        $this->assertEquals(now()->subDay()->toDateString(), $response->json('ventas_de_ayer.fecha'));
    }

    // ------------------------------------------------------------------- REQ-431 comisiones ----

    public function test_comisiones_internas_suma_todos_los_vendedores_sin_scope_de_actor(): void
    {
        $roleId = Role::where('key', 'vendedor_disenador')->value('id');
        $vendedor = User::where('role_id', $roleId)->first();
        $this->assertNotNull($vendedor, 'debe existir al menos un vendedor_disenador real sembrado en testing');

        // Fixture ANTES de cualquier request HTTP — `CommissionOrderService::rawOrders()` arma el
        // nombre de tabla a mano con `Tenant::current()->slug` (no vía Eloquent/search_path); con
        // una request HTTP de por medio antes del insert, ese resolver de tenant queda stale para
        // la siguiente request y el pedido recién creado deja de verse (mismo gotcha, en espíritu,
        // que el resto de fixtures de este archivo, que también crean todo antes del primer
        // `getJson()` — ver `AdminContReportsControllerTest::makeCommissionableOrder()`).
        //
        // $10,000 cobrado por completo, sin marcar pagado al vendedor todavía => tramo 1.5% (seed
        // por defecto) => $150 en estado "por_pagar" (mismo cálculo ya probado en
        // AdminContReportsControllerTest::test_comisiones_hoy_suma_internas_y_externas_ya_realizadas).
        $invoice = $this->makeCommissionableOrder((int) $vendedor->id, 10000.0);
        $this->makeConfirmedPayment($invoice, 10000.0, now()->toDateTimeString());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals(150.0, $response->json('comisiones_por_pagar.internas'));
    }

    public function test_comisiones_externas_reusa_pendiente_factura_de_architect_commission_service(): void
    {
        // Llamado directo ANTES de cualquier request HTTP, mismo motivo que el test de arriba.
        $esperado = app(ArchitectCommissionService::class)->summary()['pendiente_factura'];

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $this->assertEquals($esperado, $response->json('comisiones_por_pagar.externas'));
    }
}
