<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Support\ExcelMoney;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\Feature\AdminCont\Concerns\ReadsXlsxResponse;
use Tests\Feature\FeatureTestCase;

/** Batch 23 Grupo 2 de Admin&Cont (SCRUM-651→660, REQ-574→583) — "Reporte mensual por cliente"
 *  (4M1, agrupación 'dia') y "Mensual por cliente — acumulado" (4M2, agrupación 'mes'). */
class AdminContClientReportsControllerTest extends FeatureTestCase
{
    use ReadsXlsxResponse;

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

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

    private function loginAsDesigner(): string
    {
        return $this->loginAs(self::DESIGNER_EMAIL, 'Password123!', 'designer');
    }

    /** @return array{0: int, 1: int} */
    private function makeClientWithProject(string $projectName): array
    {
        $master = MasterClient::create(['name' => 'Cliente Reportes '.self::uniqueTestNumber()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Reportes '.self::uniqueTestNumber(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName]);

        return [$master->id, $project->id];
    }

    private function makeInvoice(int $masterClientId, ?int $salesProjectId, float $monto, float $saldoAplicado, string $createdAt): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-CR-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente Reportes', 'master_client_id' => $masterClientId, 'sales_project_id' => $salesProjectId,
            'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    private function makePayment(AdminContInvoice $invoice, float $montoAplicado, string $metodo, string $paymentCreatedAt): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-CR-'.self::uniqueTestNumber(), 'master_client_id' => $invoice->master_client_id,
            'monto_recibido' => $montoAplicado, 'saldo_favor_aplicado' => 0, 'metodo_pago' => $metodo,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $montoAplicado]);
        $payment->forceFill(['created_at' => $paymentCreatedAt])->save();
        $invoice->increment('saldo_aplicado', $montoAplicado);
    }

    // ------------------------------------------------------------------- Gate de permisos ----

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/mensual-cliente')->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/mensual-cliente-acumulado')->assertStatus(403);
    }

    // ------------------------------------------------------------------- RN1 sin cliente ----

    public function test_sin_master_client_id_devuelve_estado_sin_cliente(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/mensual-cliente')
            ->assertStatus(200);

        $response->assertJson(['estado' => 'sin_cliente']);
        $this->assertArrayNotHasKey('resumen', $response->json());
    }

    // ------------------------------------------------------------------- Resumen + agrupación ----

    public function test_reporte_dia_agrupa_por_fecha_de_cobro_no_de_factura(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto Reportes A');

        // Factura de enero, cobrada en marzo — el filtro de fecha usa la factura (enero), pero la
        // fila de la tabla debe agruparse por la fecha del COBRO (marzo), no por la de la factura.
        $invoice = $this->makeInvoice($masterClientId, $projectId, 5000.0, 0, '2026-01-10 10:00:00');
        $this->makePayment($invoice, 5000.0, AdminContPayment::METODO_TRANSFERENCIA, '2026-03-15 09:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/reports/mensual-cliente?master_client_id={$masterClientId}&desde=2026-01-01&hasta=2026-01-31")
            ->assertStatus(200);

        $response->assertJson(['estado' => 'ok', 'resumen' => ['registros' => 1, 'total_facturado' => 5000.0, 'total_cobrado' => 5000.0, 'total_pendiente' => 0.0]]);
        $filas = $response->json('filas');
        $this->assertCount(1, $filas);
        $this->assertSame('2026-03-15', $filas[0]['fecha']);
        $this->assertEquals(5000.0, $filas[0]['transferencia']);
        $this->assertEquals(5000.0, $filas[0]['total_caja']);
    }

    public function test_mismo_dataset_agrupado_por_dia_vs_mes_da_mismo_total_distintas_filas(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto Reportes B');

        $inv1 = $this->makeInvoice($masterClientId, $projectId, 2000.0, 0, '2026-06-01 10:00:00');
        $this->makePayment($inv1, 2000.0, AdminContPayment::METODO_CHEQUE, '2026-06-05 09:00:00');
        $inv2 = $this->makeInvoice($masterClientId, $projectId, 3000.0, 0, '2026-06-02 10:00:00');
        $this->makePayment($inv2, 3000.0, AdminContPayment::METODO_CHEQUE, '2026-06-20 09:00:00');

        $token = $this->loginAsFelix();
        $dia = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/reports/mensual-cliente?master_client_id={$masterClientId}&desde=2026-06-01&hasta=2026-06-30")
            ->assertStatus(200);
        $mes = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/reports/mensual-cliente-acumulado?master_client_id={$masterClientId}&desde=2026-06-01&hasta=2026-06-30")
            ->assertStatus(200);

        $this->assertCount(2, $dia->json('filas'));
        $this->assertCount(1, $mes->json('filas'));
        $this->assertSame('Junio', $mes->json('filas.0.mes'));
        $this->assertSame(2026, $mes->json('filas.0.anio'));
        $this->assertEquals(5000.0, $dia->json('totales.importe'));
        $this->assertEquals(5000.0, $mes->json('totales.importe'));
    }

    public function test_todos_los_clientes_combina_las_facturas_de_todos(): void
    {
        [$masterClientId1, $projectId1] = $this->makeClientWithProject('Proyecto Reportes C1');
        [$masterClientId2, $projectId2] = $this->makeClientWithProject('Proyecto Reportes C2');

        $inv1 = $this->makeInvoice($masterClientId1, $projectId1, 1000.0, 0, '2026-07-01 10:00:00');
        $this->makePayment($inv1, 1000.0, AdminContPayment::METODO_EFECTIVO, '2026-07-01 10:00:00');
        $inv2 = $this->makeInvoice($masterClientId2, $projectId2, 1500.0, 0, '2026-07-02 10:00:00');
        $this->makePayment($inv2, 1500.0, AdminContPayment::METODO_EFECTIVO, '2026-07-02 10:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/mensual-cliente?master_client_id=todos&desde=2026-07-01&hasta=2026-07-31')
            ->assertStatus(200);

        $response->assertJson(['resumen' => ['registros' => 2, 'total_facturado' => 2500.0]]);
    }

    public function test_columna_otros_agrupa_metodos_fuera_de_los_4_principales(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto Reportes D');

        $invoice = $this->makeInvoice($masterClientId, $projectId, 900.0, 0, '2026-08-05 10:00:00');
        $this->makePayment($invoice, 900.0, AdminContPayment::METODO_DEPOSITO, '2026-08-05 10:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/reports/mensual-cliente?master_client_id={$masterClientId}&desde=2026-08-01&hasta=2026-08-31")
            ->assertStatus(200);

        $this->assertEquals(900.0, $response->json('filas.0.otros'));
        $this->assertEquals(0.0, $response->json('filas.0.transferencia'));
    }

    public function test_facturas_pendientes_aparecen_por_separado_con_proyecto(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto Reportes Pendiente');
        $this->makeInvoice($masterClientId, $projectId, 6200.0, 0, '2026-09-01 10:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/reports/mensual-cliente?master_client_id={$masterClientId}&desde=2026-09-01&hasta=2026-09-30")
            ->assertStatus(200);

        $response->assertJson(['resumen' => ['total_pendiente' => 6200.0]]);
        $pendientes = $response->json('pendientes');
        $this->assertCount(1, $pendientes);
        $this->assertSame('Proyecto Reportes Pendiente', $pendientes[0]['proyecto']);
        $this->assertEquals(6200.0, $pendientes[0]['monto']);
    }

    // ------------------------------------------------------------------- Export Excel ----

    public function test_export_sin_cliente_devuelve_422(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/mensual-cliente/excel')
            ->assertStatus(422);
    }

    public function test_export_con_datos_devuelve_xlsx(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto Reportes Excel');
        $invoice = $this->makeInvoice($masterClientId, $projectId, 1200.0, 0, '2026-10-01 10:00:00');
        $this->makePayment($invoice, 1200.0, AdminContPayment::METODO_YAPPY, '2026-10-01 10:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/reports/mensual-cliente/excel?master_client_id={$masterClientId}&desde=2026-10-01&hasta=2026-10-31");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ------------------------------------------------- SCRUM-935 — formato de moneda y sin "Media" ----

    public function test_935_mensual_excel_escribe_numeros_con_formato_de_moneda_y_sin_columna_media(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto 935 Mensual');
        $invoice = $this->makeInvoice($masterClientId, $projectId, 1200.0, 0, '2026-10-01 10:00:00');
        $this->makePayment($invoice, 1200.0, AdminContPayment::METODO_YAPPY, '2026-10-01 10:00:00');

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->get("/api/admin-contab/reports/mensual-cliente/excel?master_client_id={$masterClientId}&desde=2026-10-01&hasta=2026-10-31")
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();

        $this->assertSame(
            ['Fecha', 'Núm.', 'Importe', 'Transferencia', 'Cheque', 'Efectivo', 'Yappy', 'Otros', 'Total Caja'],
            $sheet->toArray()[0],
        );
        $this->assertNotContains('Media', $sheet->toArray()[0]);

        // Fila de datos (2) y fila "Total del período" (3): Importe(C) … Total Caja(I) son moneda.
        foreach ([2, 3] as $row) {
            $this->assertMoneyCell($sheet, "C{$row}", 1200.0);
            $this->assertMoneyCell($sheet, "G{$row}", 1200.0);
            $this->assertMoneyCell($sheet, "I{$row}", 1200.0);
            $this->assertMoneyCell($sheet, "D{$row}", 0.0);
        }
        // "Núm." es conteo: número real pero SIN formato de moneda.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame(1, (int) $sheet->getCell('B2')->getValue());
        $this->assertNotSame(ExcelMoney::FORMAT, $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
    }

    public function test_935_acumulado_excel_escribe_numeros_con_formato_de_moneda_y_sin_columna_media(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto 935 Acumulado');
        $invoice = $this->makeInvoice($masterClientId, $projectId, 800.5, 0, '2026-10-05 10:00:00');
        $this->makePayment($invoice, 800.5, AdminContPayment::METODO_YAPPY, '2026-10-05 10:00:00');

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->get("/api/admin-contab/reports/mensual-cliente-acumulado/excel?master_client_id={$masterClientId}&desde=2026-10-01&hasta=2026-10-31")
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();

        $this->assertSame(
            ['Año', 'Mes', 'Núm.', 'Importe', 'Transferencia', 'Cheque', 'Efectivo', 'Yappy', 'Otros', 'Total Caja'],
            $sheet->toArray()[0],
        );
        foreach ([2, 3] as $row) {
            $this->assertMoneyCell($sheet, "D{$row}", 800.5);   // Importe
            $this->assertMoneyCell($sheet, "H{$row}", 800.5);   // Yappy
            $this->assertMoneyCell($sheet, "J{$row}", 800.5);   // Total Caja
        }
    }

    public function test_935_hoja_pendientes_del_excel_tambien_lleva_el_monto_como_moneda(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto 935 Pendiente');
        $cobrada = $this->makeInvoice($masterClientId, $projectId, 500.0, 0, '2026-10-01 10:00:00');
        $this->makePayment($cobrada, 500.0, AdminContPayment::METODO_YAPPY, '2026-10-01 10:00:00');
        $this->makeInvoice($masterClientId, $projectId, 6200.0, 0, '2026-10-02 10:00:00'); // sin cobrar → Pendientes

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->get("/api/admin-contab/reports/mensual-cliente/excel?master_client_id={$masterClientId}&desde=2026-10-01&hasta=2026-10-31")
            ->assertStatus(200);
        $pendientes = $this->loadWorkbook($response)->getSheetByName('Pendientes');

        $this->assertNotNull($pendientes);
        $this->assertMoneyCell($pendientes, 'D2', 6200.0);
    }

    public function test_935_json_del_reporte_ya_no_expone_media_ni_en_filas_ni_en_totales(): void
    {
        [$masterClientId, $projectId] = $this->makeClientWithProject('Proyecto 935 Json');
        $invoice = $this->makeInvoice($masterClientId, $projectId, 300.0, 0, '2026-10-01 10:00:00');
        $this->makePayment($invoice, 300.0, AdminContPayment::METODO_YAPPY, '2026-10-01 10:00:00');

        foreach (['mensual-cliente', 'mensual-cliente-acumulado'] as $reporte) {
            $json = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
                ->getJson("/api/admin-contab/reports/{$reporte}?master_client_id={$masterClientId}&desde=2026-10-01&hasta=2026-10-31")
                ->assertStatus(200)
                ->json();

            $this->assertNotEmpty($json['filas'], $reporte);
            $this->assertArrayNotHasKey('media', $json['filas'][0], "{$reporte}: filas");
            $this->assertArrayNotHasKey('media', $json['totales'], "{$reporte}: totales");
            $this->assertArrayHasKey('importe', $json['totales'], "{$reporte}: el resto del contrato no cambia");
        }
    }
}
