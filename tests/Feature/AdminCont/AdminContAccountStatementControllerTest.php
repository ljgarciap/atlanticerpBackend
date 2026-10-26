<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\AccountStatementPdfService;
use App\Modules\AdminCont\Services\AccountStatementService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminCont\Concerns\ReadsXlsxResponse;
use Tests\Feature\FeatureTestCase;

/** Batch 8/9 de Admin&Cont — Estado de Cuenta (SCRUM-529→538, REQ-452→461). */
class AdminContAccountStatementControllerTest extends FeatureTestCase
{
    use ReadsXlsxResponse;

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    /** @return array{master: MasterClient, sub: SubClient, project: SalesProject} */
    private function makeClientWithProject(string $suffix = ''): array
    {
        $master = MasterClient::create(['name' => 'Cliente EC '.$suffix.uniqid(), 'default_price_type' => 'project']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '8-'.random_int(100, 999)]);
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
        AdminContFiscalSettings::current()->update(['responsable_administracion_user_id' => $this->userIdByEmailHelper(self::CONTABILIDAD_EMAIL)]);
        AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4521', 'numero_cuenta_completo' => '03-01-01-074521-4', 'moneda' => 'USD', 'activa' => true]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertNotNull($response->json('pago_a'));
        $this->assertStringContainsString('4521', (string) $response->json('cuenta_pago'));
        $this->assertStringContainsString('Contabilidad', (string) $response->json('responsable'));
    }

    private function userIdByEmailHelper(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    // SCRUM-857 (REQ-458) — con `numero_cuenta_completo` cargado, el bloque de pago usa el número
    // real (el cliente lo necesita para pagar por transferencia), no el enmascarado.
    public function test_bloque_de_pago_usa_el_numero_completo_cuando_la_cuenta_lo_tiene_cargado(): void
    {
        ['master' => $master] = $this->makeClientWithProject('PagoCompleto');
        AdminContFiscalSettings::current()->update(['responsable_administracion_user_id' => $this->userIdByEmailHelper(self::CONTABILIDAD_EMAIL)]);
        AdminContBankAccount::create([
            'banco' => 'Banco General', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '4455',
            'numero_cuenta_completo' => '00-00-00-000000-1', 'moneda' => 'USD', 'activa' => true,
        ]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame('00-00-00-000000-1', $response->json('numero_cuenta'));
        $this->assertSame('Banco General', $response->json('banco'));
        $this->assertSame('corriente', $response->json('tipo_cuenta'));
        $this->assertStringContainsString('00-00-00-000000-1', (string) $response->json('cuenta_pago'));
        $this->assertStringNotContainsString('****', (string) $response->json('cuenta_pago'));
    }

    /** SCRUM-928 (D-6) — antes este caso mostraba `****4521`; un documento al cliente nunca lleva asteriscos. */
    public function test_928_bloque_de_pago_no_enmascara_si_la_cuenta_no_tiene_numero_completo(): void
    {
        ['master' => $master] = $this->makeClientWithProject('PagoSinNumero');
        AdminContBankAccount::create(['banco' => 'Banco General', 'ultimos_4_digitos' => '4521', 'activa' => true]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('numero_cuenta'));
        $this->assertNull($response->json('cuenta_pago'));
        $this->assertStringNotContainsString('*', (string) json_encode($response->json()), 'ningún campo del estado de cuenta lleva asteriscos');
        $this->assertSame('Banco General', $response->json('banco'), 'el banco sí se sigue mostrando');
    }

    public function test_928_numero_completo_en_blanco_cuenta_como_sin_numero(): void
    {
        ['master' => $master] = $this->makeClientWithProject('PagoBlanco');
        AdminContBankAccount::create(['banco' => 'Banco General', 'ultimos_4_digitos' => '4521', 'numero_cuenta_completo' => '   ', 'activa' => true]);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('numero_cuenta'));
    }

    // ── SCRUM-857 (REQ-456) — PDF real, ya no window.print() ─────────────────────────────────

    public function test_pdf_devuelve_un_documento_con_content_type_correcto(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Pdf1');
        $this->makeInvoice($master->id, $project->id, 100);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/account-statement/pdf?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // Senior Review (2026-09-08) — el pie de página interpola nombre_comercial/direccion_fiscal
    // dentro de un string PHP en el bloque <script type="text/php"> de dompdf (único mecanismo
    // con paginación real). AccountStatementPdfService::viewData() escapa ese valor con
    // addslashes() por las dudas — verificado que dompdf de hecho degrada en silencio (nunca un
    // 500) si el string queda mal formado ahí, así que esto no previene un crash real, pero sigue
    // siendo lo correcto para no perder/mostrar mal el pie de página cuando el nombre/dirección
    // real de la empresa (dato editable por un admin) tenga una comilla.
    public function test_pdf_no_se_rompe_si_el_nombre_o_direccion_fiscal_tienen_comillas(): void
    {
        ['master' => $master] = $this->makeClientWithProject('PdfComillas');
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'O\'Brien "Atlantic" S.A.',
            'direccion_fiscal' => 'Calle "5", Panamá',
        ]);
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/account-statement/pdf?master_client_id={$master->id}")
            ->assertStatus(200);
    }

    // Pre-QA (2026-09-09) — el test de arriba solo afirma HTTP 200; dompdf degrada en silencio
    // (nunca 500) así que no hubiera agarrado que {{ $footerCompanyText }} en
    // account-statement-pdf.blade.php le aplicaba un SEGUNDO escape (htmlspecialchars) al valor
    // que PdfFooterText::forFiscal() ya había escapado con addslashes() para el contexto de
    // <script type="text/php"> de dompdf — el pie de página real mostraba "Corp\&#039;s" en vez de
    // "Corp's" (mismo helper compartido con payment-receipt-pdf.blade.php — ver PdfFooterTextTest
    // y AdminContPaymentControllerTest::
    // test_pdf_del_recibo_no_duplica_el_escape_de_comillas_en_el_pie_de_pagina). Este test
    // renderiza la vista Blade directamente para verificar el HTML/PHP real, no el binario PDF.
    public function test_pdf_no_duplica_el_escape_de_comillas_en_el_pie_de_pagina(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('PdfComillasEscape');
        $this->makeInvoice($master->id, $project->id, 100);
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => "Atlantic USA Corp's",
            'direccion_fiscal' => 'Calle 57 Este, Panama',
        ]);

        $detail = app(AccountStatementService::class)->detail($master->id, null, null, null);
        $viewData = app(AccountStatementPdfService::class)->viewData($detail);
        $html = view('admin-cont.account-statement-pdf', $viewData)->render();

        // Scope al bloque <script type="text/php"> del pie de página — ver comentario equivalente
        // en AdminContPaymentControllerTest.
        $this->assertStringContainsString('<script type="text/php">', $html);
        $footerScript = substr($html, (int) strpos($html, '<script type="text/php">'));
        $this->assertStringContainsString('$companyText = "Atlantic USA Corp\'s - Calle 57 Este, Panama";', $footerScript);
        $this->assertStringNotContainsString('&#039;', $footerScript);
    }

    public function test_pdf_sin_cliente_falla_422(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/account-statement/pdf')
            ->assertStatus(422);
    }

    public function test_pdf_rechaza_a_un_rol_sin_visibilidad_del_modulo(): void
    {
        ['master' => $master] = $this->makeClientWithProject('Pdf2');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement/pdf?master_client_id={$master->id}")
            ->assertStatus(403);
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

    // ── SCRUM-939 — formato de moneda (montos como celdas numéricas, no texto) ─────────────

    public function test_939_excel_escribe_saldo_y_movimientos_como_celdas_numericas_con_formato_de_moneda(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Money');
        $this->makeInvoice($master->id, $project->id, 250.0);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/account-statement/export?master_client_id={$master->id}")
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $rows = $sheet->toArray();

        // El "Saldo actual"/"Saldo a favor del cliente" vive en la columna B, en una fila que depende
        // del resto del bloque de encabezado — se ubica por etiqueta en vez de hardcodear el número de fila.
        $saldoRow = null;
        foreach ($rows as $i => $row) {
            if (in_array($row[0], ['Saldo actual', 'Saldo a favor del cliente'], true)) {
                $saldoRow = $i + 1;
                break;
            }
        }
        $this->assertNotNull($saldoRow, 'no se encontró la fila de Saldo en el encabezado');
        $this->assertMoneyCell($sheet, "B{$saldoRow}", 250.0);

        // Tabla de movimientos: Débito/Crédito/Saldo (columnas G/H/I) — se ubica por el encabezado
        // de la tabla, que también depende del tamaño del bloque de encabezado superior.
        $tableHeaderRow = null;
        foreach ($rows as $i => $row) {
            if (($row[6] ?? null) === 'Débito') {
                $tableHeaderRow = $i + 1;
                break;
            }
        }
        $this->assertNotNull($tableHeaderRow, 'no se encontró el encabezado de la tabla de movimientos');
        $dataRow = $tableHeaderRow + 1;
        $this->assertMoneyCell($sheet, "G{$dataRow}", 250.0);
        $this->assertMoneyCell($sheet, "H{$dataRow}", 0.0);
        $this->assertMoneyCell($sheet, "I{$dataRow}", 250.0);
    }

    // ── SCRUM-877 parte 1 — notas de crédito y anulaciones en el Estado de Cuenta ────────────

    /** @return array<string, mixed> */
    private function creditNoteBase(int $masterClientId): array
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $registra = (int) DB::table("{$slug}_auth.users")->where('email', self::CONTABILIDAD_EMAIL)->value('id');

        return [
            'master_client_id' => $masterClientId, 'cliente' => 'Cliente Test',
            'monto' => 0, 'subtotal' => 0, 'itbms' => 0, 'motivo' => 'Test',
            'registrado_por' => $registra, 'fecha' => now(),
            'estado' => AdminContCreditNote::ESTADO_APLICADA,
        ];
    }

    private function movimientosFor(int $masterClientId): array
    {
        return $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$masterClientId}")
            ->assertStatus(200)
            ->json('movimientos');
    }

    public function test_877_descuento_comercial_aparece_como_credito_por_el_monto_completo(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Desc');
        $invoice = $this->makeInvoice($master->id, $project->id, 1000, 0.0, '2026-05-01 09:00:00');
        AdminContCreditNote::create([
            ...$this->creditNoteBase($master->id), 'numero' => 'NC-D1', 'factura_origen_id' => $invoice->id,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'monto' => 250, 'monto_aplicado_saldo' => 250, 'fecha' => '2026-05-10',
        ]);

        $movimientos = $this->movimientosFor($master->id);
        $descuento = collect($movimientos)->firstWhere('tipo', 'descuento');
        $this->assertNotNull($descuento);
        $this->assertSame(250.0, (float) $descuento['credito']);
        $this->assertSame('2026-05-10', $descuento['fecha']);
        // Saldo final: 1000 débito - 250 crédito.
        $this->assertSame(750.0, (float) collect($movimientos)->last()['saldo']);
    }

    public function test_877_devolucion_con_excedente_devuelto_solo_muestra_lo_que_redujo_la_factura(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Dev');
        $invoice = $this->makeInvoice($master->id, $project->id, 300, 0.0, '2026-05-01 09:00:00');
        // Nota por 500, de los cuales solo 300 redujeron la factura; 200 se devolvieron en efectivo.
        AdminContCreditNote::create([
            ...$this->creditNoteBase($master->id), 'numero' => 'NC-DEV', 'factura_origen_id' => $invoice->id,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'resultado' => AdminContCreditNote::RESULTADO_DEVUELTO,
            'monto' => 500, 'monto_aplicado_saldo' => 300, 'fecha' => '2026-05-05', 'fecha_decision' => '2026-05-06',
        ]);

        $movimientos = $this->movimientosFor($master->id);
        $devolucion = collect($movimientos)->firstWhere('tipo', 'devolucion');
        $this->assertSame(300.0, (float) $devolucion['credito']);
        $this->assertSame('2026-05-06', $devolucion['fecha']); // fecha_decision
        $this->assertSame(0.0, (float) collect($movimientos)->last()['saldo']);
    }

    public function test_877_nota_saldo_favor_puede_dejar_el_saldo_a_favor(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('SF');
        $invoice = $this->makeInvoice($master->id, $project->id, 200, 0.0, '2026-05-01 09:00:00');
        AdminContCreditNote::create([
            ...$this->creditNoteBase($master->id), 'numero' => 'NC-SF', 'factura_origen_id' => $invoice->id,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR,
            'monto' => 350, 'monto_aplicado_saldo' => 200, 'fecha' => '2026-05-08',
        ]);

        $movimientos = $this->movimientosFor($master->id);
        // Crédito = monto completo (350), no solo lo que redujo la factura → saldo -150.
        $this->assertSame(350.0, (float) collect($movimientos)->firstWhere('tipo', 'descuento')['credito']);
        $this->assertSame(-150.0, (float) collect($movimientos)->last()['saldo']);
    }

    public function test_877_factura_anulada_se_muestra_con_fila_de_reversa_neto_cero(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Anul');
        $invoice = $this->makeInvoice($master->id, $project->id, 800, 0.0, '2026-05-01 09:00:00');
        $invoice->forceFill(['anulada_at' => '2026-05-20 10:00:00'])->save();
        AdminContCreditNote::create([
            ...$this->creditNoteBase($master->id), 'numero' => 'NC-ANU', 'factura_origen_id' => $invoice->id,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'monto' => 800, 'monto_aplicado_saldo' => 800, 'fecha' => '2026-05-20', 'fecha_decision' => '2026-05-21',
        ]);

        $movimientos = $this->movimientosFor($master->id);
        $tipos = collect($movimientos)->pluck('tipo')->all();
        $this->assertContains('factura', $tipos);
        $this->assertContains('anulacion', $tipos);
        // La anulación NO genera una fila extra de tipo "descuento"/"devolucion".
        $this->assertNotContains('descuento', $tipos);
        $this->assertSame(800.0, (float) collect($movimientos)->firstWhere('tipo', 'anulacion')['credito']);
        $this->assertSame('2026-05-21', collect($movimientos)->firstWhere('tipo', 'anulacion')['fecha']);
        $this->assertSame(0.0, (float) collect($movimientos)->last()['saldo']);
    }

    public function test_877_notas_pendientes_de_aprobacion_no_aparecen(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Pend');
        $invoice = $this->makeInvoice($master->id, $project->id, 500, 0.0, '2026-05-01 09:00:00');
        AdminContCreditNote::create([
            ...$this->creditNoteBase($master->id), 'numero' => 'NC-P', 'factura_origen_id' => $invoice->id,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'monto' => 100, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
        ]);

        $movimientos = $this->movimientosFor($master->id);
        $this->assertCount(1, $movimientos); // solo la factura
        $this->assertSame(500.0, (float) collect($movimientos)->last()['saldo']);
    }

    // ── SCRUM-947 — paginación estándar sobre `movimientos` ─────────────────────────────────

    public function test_947_pagina_1_con_20_por_pagina_por_defecto(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Pag947Default');
        $this->makeInvoice($master->id, $project->id, 100);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $this->assertSame(20, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * El saldo/nota de la tarjeta y el saldo CORRIDO de cada fila siguen calculados sobre el
     * TOTAL de movimientos (`detail()` los calcula antes de paginar) — paginar solo recorta qué
     * filas viajan en la respuesta, nunca las cifras agregadas ni el saldo corrido de cada una.
     */
    public function test_947_pagina_movimientos_sin_afectar_saldo_de_la_tarjeta_ni_el_saldo_corrido(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Pag947');
        for ($i = 1; $i <= 12; $i++) {
            $this->makeInvoice($master->id, $project->id, 100, 0, sprintf('2026-01-%02d 00:00:00', $i));
        }
        $token = $this->loginAsFelix();

        $page1 = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&per_page=10&page=1")
            ->assertStatus(200);

        $this->assertCount(10, $page1->json('movimientos'));
        $this->assertSame(12, $page1->json('meta.total'));
        $this->assertSame(10, $page1->json('meta.per_page'));
        $this->assertSame(1, $page1->json('meta.current_page'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        // Tarjeta de saldo: 12 x 100 = 1200, el TOTAL, no solo lo que viajó en esta página.
        $this->assertSame(1200.0, (float) $page1->json('saldo'));
        // Saldo corrido de la última fila de la página 1 — acumulado real hasta ahí (10 x 100).
        $this->assertSame(1000.0, (float) collect($page1->json('movimientos'))->last()['saldo']);

        $page2 = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}&per_page=10&page=2")
            ->assertStatus(200);

        $this->assertCount(2, $page2->json('movimientos'));
        $this->assertSame(2, $page2->json('meta.current_page'));
        $this->assertSame(1200.0, (float) $page2->json('saldo'));
        // El saldo corrido de la página 2 sigue acumulando desde donde terminó la página 1 (no
        // reinicia en 0) — confirma que el corte de paginación pasa DESPUÉS de calcular el saldo
        // corrido sobre el total, nunca antes.
        $this->assertSame(1200.0, (float) collect($page2->json('movimientos'))->last()['saldo']);
    }

    // ── SCRUM-955 punto 5 — columna "Cobro" (referencia al recibo por fila) ─────────────────────

    public function test_movimiento_de_cobro_incluye_referencia_al_recibo(): void
    {
        ['master' => $master, 'project' => $project] = $this->makeClientWithProject('Recibo');
        $invoice = $this->makeInvoice($master->id, $project->id, 300);
        $payment = $this->makeConfirmedPayment($master->id, $invoice, 300);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/account-statement?master_client_id={$master->id}")
            ->assertStatus(200);

        $filaFactura = collect($response->json('movimientos'))->firstWhere('tipo', 'factura');
        $filaCobro = collect($response->json('movimientos'))->firstWhere('tipo', 'cobro');

        $this->assertNull($filaFactura['recibo']);
        $this->assertSame([
            'tipo' => 'cobro',
            'id' => $payment->id,
            'numero_recibo' => $payment->numero_recibo,
        ], $filaCobro['recibo']);
    }
}
