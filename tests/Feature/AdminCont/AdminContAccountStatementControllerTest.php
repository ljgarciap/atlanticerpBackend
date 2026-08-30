<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Batch 8/9 de Admin&Cont — Estado de Cuenta (SCRUM-529→538, REQ-452→461). */
class AdminContAccountStatementControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'conta@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    /** @return array{master: MasterClient, sub: SubClient, project: SalesProject} */
    private function makeClientWithProject(string $suffix = ''): array
    {
        $master  = MasterClient::create(['name' => 'Cliente EC '.$suffix.uniqid(), 'default_price_type' => 'project']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '8-'.random_int(100, 999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.$suffix.uniqid()]);

        return ['master' => $master, 'sub' => $sub, 'project' => $project];
    }

    private function makeInvoice(int $masterClientId, ?int $salesProjectId, float $monto, float $saldoAplicado = 0.0, ?string $createdAt = null): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.random_int(100000, 999999), 'order_id' => random_int(1000000, 9999999),
            'master_client_id' => $masterClientId, 'sales_project_id' => $salesProjectId,
            'cliente' => 'Cliente Test', 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        if ($createdAt !== null) {
            $invoice->forceFill(['created_at' => $createdAt])->save();
        }

        return $invoice;
    }

    private function makeConfirmedPayment(int $masterClientId, AdminContInvoice $invoice, float $montoAplicado, ?string $createdAt = null): AdminContPayment
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-'.random_int(100000, 999999), 'master_client_id' => $masterClientId,
            'monto_recibido' => $montoAplicado, 'saldo_favor_aplicado' => 0, 'metodo_pago' => 'efectivo',
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $montoAplicado]);
        if ($createdAt !== null) {
            $payment->forceFill(['created_at' => $createdAt])->save();
        }

        return $payment;
    }

    // ── Gate de permisos ─────────────────────────────────────────────────────────────────────

    public function test_show_rechaza_a_un_rol_sin_visibilidad_del_modulo(): void
    {
        ['master' => $master] = $this->makeClientWithProject();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(403);
    }

    // ── REQ-453 — buscador de cliente + proyectos dependientes ──────────────────────────────

    public function test_clients_reusa_el_buscador_de_master_client(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Buscar');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/account-statement/clients?search='.urlencode($master->name))
            ->assertStatus(200);

        $this->assertContains($master->id, array_column($response->json('data'), 'id'));
    }

    public function test_projects_solo_devuelve_los_del_cliente_elegido(): void
    {
        ['master' => $m1, 'project' => $p1] = $this->makeClientWithProject('A');
        ['master' => $m2, 'project' => $p2] = $this->makeClientWithProject('B');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement/projects?master_client_id={$m1->id}")
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($p1->id, $ids);
        $this->assertNotContains($p2->id, $ids);
    }

    public function test_projects_sin_master_client_id_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/account-statement/projects')
            ->assertStatus(422);
    }

    // ── RN2 REQ-453 — buscar sin cliente no está permitido ───────────────────────────────────

    public function test_show_sin_master_client_id_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/account-statement')
            ->assertStatus(422);
    }

    public function test_show_con_cliente_inexistente_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/account-statement?master_client_id=999999')
            ->assertStatus(422);
    }

    // ── REQ-455 — encabezado (cliente/proyecto/tarifa/régimen/términos) ─────────────────────

    public function test_show_sin_proyecto_devuelve_solo_el_cliente_y_defaults_de_billing_profile(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Solo');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame($master->name, $response->json('cliente'));
        $this->assertNull($response->json('proyecto'));
        $this->assertSame('project', $response->json('tarifa'));
        // RN2 REQ-455 — sin fila propia en client_billing_profiles, cae al default razonable.
        $this->assertSame('con_itbms', $response->json('regimen_fiscal'));
        $this->assertSame('Neto 30', $response->json('terminos_pago'));
    }

    public function test_show_con_proyecto_incluye_cliente_y_proyecto_en_el_encabezado(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Filtrado');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&sales_project_id={$project->id}")
            ->assertStatus(200);

        $this->assertSame($master->name, $response->json('cliente'));
        $this->assertSame($project->name, $response->json('proyecto'));
    }

    public function test_show_con_proyecto_de_otro_cliente_falla_422(): void
    {
        ['master' => $m1] = $this->makeClientWithProject('C1');
        ['project' => $ajeno] = $this->makeClientWithProject('C2');
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$m1->id}&sales_project_id={$ajeno->id}")
            ->assertStatus(422);
    }

    public function test_show_usa_el_billing_profile_real_cuando_existe(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Perfil');
        AdminContClientBillingProfile::create([
            'master_client_id' => $master->id, 'regimen_fiscal' => 'sin_itbms', 'terminos_pago' => 'Contado',
        ]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame('sin_itbms', $response->json('regimen_fiscal'));
        $this->assertSame('Contado', $response->json('terminos_pago'));
    }

    // ── REQ-456 RN2 — export Excel ───────────────────────────────────────────────────────────

    public function test_export_excel_devuelve_un_archivo_valido(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Excel');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/account-statement/export?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_export_excel_sin_cliente_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/account-statement/export')
            ->assertStatus(422);
    }

    // ── Batch 9 (SCRUM-534→538, REQ-457→461) ─────────────────────────────────────────────────

    // ── REQ-457 — tarjeta de saldo ────────────────────────────────────────────────────────────

    public function test_saldo_actual_cuando_el_cliente_debe_dinero(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Saldo1');
        $this->makeInvoice($master->id, $project->id, 5200);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(5200.0, (float) $response->json('saldo'));
        $this->assertFalse($response->json('saldo_a_favor'));
    }

    public function test_saldo_a_favor_cuando_el_cliente_pago_de_mas(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Saldo2');
        $invoice = $this->makeInvoice($master->id, $project->id, 8660, 8660);
        $this->makeConfirmedPayment($master->id, $invoice, 8660);
        // Segunda factura pagada de más (ej. transferencia duplicada, mismo caso del mockup real:
        // "Constructora del Istmo" pagó $500 de más) — deja un saldo a favor real de $500.
        $invoice2 = $this->makeInvoice($master->id, $project->id, 4825);
        $this->makeConfirmedPayment($master->id, $invoice2, 5325);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(-500.0, (float) $response->json('saldo'));
        $this->assertTrue($response->json('saldo_a_favor'));
    }

    // ── REQ-458 — bloque de datos de pago ────────────────────────────────────────────────────

    public function test_bloque_de_pago_siempre_presente_y_consistente_con_facturacion(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Pago');
        AdminContFiscalSettings::current()->update(['responsable_administracion_user_id' => $this->userIdByEmailHelper(self::FELIX_EMAIL)]);
        AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4521', 'moneda' => 'USD', 'activa' => true]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertNotNull($response->json('pago_a'));
        $this->assertStringContainsString('4521', (string) $response->json('cuenta_pago'));
        $this->assertStringContainsString('Felix', (string) $response->json('responsable'));
    }

    private function userIdByEmailHelper(string $email): int
    {
        return (int) \App\Models\User::where('email', $email)->value('id');
    }

    // ── REQ-459 — nota automática de contexto ────────────────────────────────────────────────

    public function test_nota_factura_muy_vencida_sin_abono(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Nota1');
        $this->makeInvoice($master->id, $project->id, 9800, 0, now()->subDays(200)->toDateTimeString());
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertStringContainsString('vencida hace', (string) $response->json('nota_contexto'));
    }

    public function test_nota_saldo_a_favor(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Nota2');
        $invoice = $this->makeInvoice($master->id, $project->id, 100);
        $this->makeConfirmedPayment($master->id, $invoice, 600);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertStringContainsString('saldo a favor', (string) $response->json('nota_contexto'));
    }

    public function test_nota_cuenta_completada_en_cero(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Nota3');
        $invoice = $this->makeInvoice($master->id, $project->id, 9200);
        $this->makeConfirmedPayment($master->id, $invoice, 9200);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame('Cuenta completada y pagada en su totalidad.', $response->json('nota_contexto'));
    }

    public function test_sin_nota_cuando_ninguna_condicion_aplica(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Nota4');
        $this->makeInvoice($master->id, $project->id, 100, 50, now()->subDays(10)->toDateTimeString());
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('nota_contexto'));
    }

    public function test_nota_y_proyectos_count_se_ocultan_al_filtrar_por_proyecto(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Nota5');
        $invoice = $this->makeInvoice($master->id, $project->id, 100);
        $this->makeConfirmedPayment($master->id, $invoice, 100);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&sales_project_id={$project->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('nota_contexto'));
        $this->assertNull($response->json('proyectos_count'));
    }

    public function test_proyectos_count_refleja_la_cantidad_real(): void
    {
        ['master' => $master, 'sub' => $sub] = $this->makeClientWithProject('Multi');
        SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Segundo proyecto']);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(2, $response->json('proyectos_count'));
    }

    // ── REQ-460 — tabla de movimientos ────────────────────────────────────────────────────────

    public function test_movimientos_incluyen_factura_como_debito_y_cobro_confirmado_como_credito(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Mov1');
        $invoice = $this->makeInvoice($master->id, $project->id, 8400, 0, '2026-05-01 00:00:00');
        $this->makeConfirmedPayment($master->id, $invoice, 8400, '2026-05-15 00:00:00');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $movimientos = $response->json('movimientos');
        $this->assertCount(2, $movimientos);
        $this->assertSame('factura', $movimientos[0]['tipo']);
        $this->assertSame(8400.0, (float) $movimientos[0]['debito']);
        $this->assertSame(8400.0, (float) $movimientos[0]['saldo']);
        $this->assertSame('cobro', $movimientos[1]['tipo']);
        $this->assertSame(8400.0, (float) $movimientos[1]['credito']);
        // RN2/Escenario 3 — saldo corrido pasa de 8400 a 0 tras el cobro.
        $this->assertSame(0.0, (float) $movimientos[1]['saldo']);
    }

    public function test_movimientos_de_cobro_esperando_confirmacion_no_cuentan(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Mov2');
        $invoice = $this->makeInvoice($master->id, $project->id, 100);
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-'.random_int(1, 999999), 'master_client_id' => $master->id,
            'monto_recibido' => 100, 'metodo_pago' => 'transferencia', 'estado' => AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => 100]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $movimientos = $response->json('movimientos');
        $this->assertCount(1, $movimientos);
        $this->assertSame('factura', $movimientos[0]['tipo']);
    }

    public function test_movimientos_ordenados_cronologicamente_con_saldo_corrido(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Mov3');
        $i1 = $this->makeInvoice($master->id, $project->id, 6200, 0, '2026-05-15 00:00:00');
        $i2 = $this->makeInvoice($master->id, $project->id, 6600, 0, '2026-05-28 00:00:00');
        $this->makeConfirmedPayment($master->id, $i1, 6200, '2026-06-05 00:00:00');
        $this->makeConfirmedPayment($master->id, $i2, 6600, '2026-06-18 00:00:00');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $fechas = array_column($response->json('movimientos'), 'fecha');
        $sorted = $fechas;
        sort($sorted);
        $this->assertSame($sorted, $fechas);
        $this->assertSame(0.0, (float) $response->json('movimientos.3.saldo'));
    }

    public function test_movimientos_respeta_el_filtro_de_fechas(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Mov4');
        $this->makeInvoice($master->id, $project->id, 100, 0, '2026-04-01 00:00:00');
        $this->makeInvoice($master->id, $project->id, 200, 0, '2026-06-01 00:00:00');
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&desde=2026-05-01&hasta=2026-06-30")
            ->assertStatus(200);

        $movimientos = $response->json('movimientos');
        $this->assertCount(1, $movimientos);
        $this->assertSame(200.0, (float) $movimientos[0]['debito']);
    }

    public function test_movimientos_solo_del_proyecto_filtrado(): void
    {
        ['master' => $master, 'sub' => $sub, 'project' => $p1] = $this->makeClientWithProject('Mov5');
        $p2 = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Otro proyecto']);
        $this->makeInvoice($master->id, $p1->id, 100);
        $this->makeInvoice($master->id, $p2->id, 200);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&sales_project_id={$p1->id}")
            ->assertStatus(200);

        $movimientos = $response->json('movimientos');
        $this->assertCount(1, $movimientos);
        $this->assertSame(100.0, (float) $movimientos[0]['debito']);
    }

    public function test_export_excel_incluye_columnas_de_movimientos(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Excel2');
        $this->makeInvoice($master->id, $project->id, 100);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/account-statement/export?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }
}
