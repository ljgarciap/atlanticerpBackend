<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\PaymentMethodSalesReportService;
use App\Modules\AdminCont\Support\ExcelMoney;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\AdminCont\Concerns\ReadsXlsxResponse;
use Tests\Feature\FeatureTestCase;

/** Batch 23 Grupo 3 de Admin&Cont (SCRUM-665→669, REQ-588→592) — "Ventas por medio de pago" (4M4). */
class AdminContPaymentMethodSalesControllerTest extends FeatureTestCase
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

    private function makeInvoice(?int $masterClientId, float $monto, float $saldoAplicado, string $createdAt, ?AdminContItbmsRate $rate = null): AdminContInvoice
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-VMP-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente VMP '.self::uniqueTestNumber(), 'master_client_id' => $masterClientId,
            'itbms_rate_id' => $rate?->id, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    private function makePayment(AdminContInvoice $invoice, float $montoAplicado, string $metodo, string $paymentCreatedAt): void
    {
        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-VMP-'.self::uniqueTestNumber(), 'master_client_id' => $invoice->master_client_id ?? MasterClient::create(['name' => 'C '.self::uniqueTestNumber()])->id,
            'monto_recibido' => $montoAplicado, 'saldo_favor_aplicado' => 0, 'metodo_pago' => $metodo,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $montoAplicado]);
        $payment->forceFill(['created_at' => $paymentCreatedAt])->save();
        $invoice->increment('saldo_aplicado', $montoAplicado);
    }

    // ------------------------------------------------------------------- Gate ----

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas-medio-pago')->assertStatus(403);
    }

    // ------------------------------------------------------------------- RN1 default "todos" ----

    public function test_sin_cliente_muestra_todos_por_default_nunca_estado_vacio(): void
    {
        $master = MasterClient::create(['name' => 'Cliente VMP Default']);
        $invoice = $this->makeInvoice($master->id, 1000.0, 0, '2026-01-01 09:00:00');
        $this->makePayment($invoice, 1000.0, AdminContPayment::METODO_TRANSFERENCIA, '2026-01-01 09:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas-medio-pago')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('estado', $response->json());
        $response->assertJson(['resumen' => ['facturas_cobradas' => 1]]);
    }

    // ------------------------------------------------------------------- 9 columnas reales ----

    public function test_monto_de_la_factura_cae_en_una_sola_de_las_9_columnas_reales(): void
    {
        $master = MasterClient::create(['name' => 'Cliente VMP Tarjeta']);
        $invoice = $this->makeInvoice($master->id, 660.0, 0, '2026-02-01 09:00:00');
        $this->makePayment($invoice, 660.0, AdminContPayment::METODO_TARJETA, '2026-02-01 09:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas-medio-pago?desde=2026-02-01&hasta=2026-02-28')
            ->assertStatus(200);

        $fila = $response->json('filas.0');
        $this->assertEquals(660.0, $fila['tarjeta']);
        foreach (AdminContPayment::METODOS as $m) {
            if ($m !== AdminContPayment::METODO_TARJETA) {
                $this->assertEquals(0.0, $fila[$m], "columna {$m} debería ser 0");
            }
        }
    }

    public function test_suma_de_las_9_columnas_de_total_del_periodo_coincide_con_el_total_del_resumen(): void
    {
        $master = MasterClient::create(['name' => 'Cliente VMP Totales']);
        $inv1 = $this->makeInvoice($master->id, 500.0, 0, '2026-03-01 09:00:00');
        $this->makePayment($inv1, 500.0, AdminContPayment::METODO_EFECTIVO, '2026-03-01 09:00:00');
        $inv2 = $this->makeInvoice($master->id, 300.0, 0, '2026-03-02 09:00:00');
        $this->makePayment($inv2, 300.0, AdminContPayment::METODO_YAPPY, '2026-03-02 09:00:00');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas-medio-pago?desde=2026-03-01&hasta=2026-03-31')
            ->assertStatus(200);

        $filas = $response->json('filas');
        $sumaColumnas = 0.0;
        foreach ($filas as $fila) {
            foreach (AdminContPayment::METODOS as $m) {
                $sumaColumnas += $fila[$m];
            }
        }
        $this->assertEquals($response->json('resumen.total'), round($sumaColumnas, 2));
    }

    // ------------------------------------------------------------------- Export ----

    public function test_export_sin_datos_devuelve_422(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/ventas-medio-pago/excel?desde=2099-01-01&hasta=2099-01-31')
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------- SCRUM-880: orden de columnas ----

    public function test_scrum880_orden_de_columnas_de_metodo_es_el_pedido_por_negocio(): void
    {
        $esperado = [
            AdminContPayment::METODO_TRANSFERENCIA,
            AdminContPayment::METODO_CHEQUE,
            AdminContPayment::METODO_EFECTIVO,
            AdminContPayment::METODO_YAPPY,
            AdminContPayment::METODO_LINK_PAGO,
            AdminContPayment::METODO_TARJETA,
            AdminContPayment::METODO_DEPOSITO,
            AdminContPayment::METODO_RETENCION_IMPUESTOS,
            AdminContPayment::METODO_AJUSTE_CUENTA,
        ];

        $this->assertSame(
            $esperado,
            PaymentMethodSalesReportService::COLUMNAS_METODO_ORDEN,
        );

        // El orden del reporte nunca puede perder ni agregar un método frente al vocabulario canónico.
        $this->assertEqualsCanonicalizing(
            AdminContPayment::METODOS,
            PaymentMethodSalesReportService::COLUMNAS_METODO_ORDEN,
        );

        // Y la constante compartida no se tocó.
        $this->assertSame(
            [
                AdminContPayment::METODO_TRANSFERENCIA, AdminContPayment::METODO_CHEQUE,
                AdminContPayment::METODO_EFECTIVO, AdminContPayment::METODO_TARJETA,
                AdminContPayment::METODO_DEPOSITO, AdminContPayment::METODO_YAPPY,
                AdminContPayment::METODO_LINK_PAGO, AdminContPayment::METODO_RETENCION_IMPUESTOS,
                AdminContPayment::METODO_AJUSTE_CUENTA,
            ],
            AdminContPayment::METODOS,
        );
    }

    public function test_scrum880_excel_pone_las_columnas_de_metodo_despues_de_total_en_el_orden_nuevo(): void
    {
        $master = MasterClient::create(['name' => 'Cliente VMP Orden']);
        $invoice = $this->makeInvoice($master->id, 500.0, 0, '2026-04-01 09:00:00');
        $this->makePayment($invoice, 500.0, AdminContPayment::METODO_YAPPY, '2026-04-01 09:00:00');

        $token = $this->loginAsFelix();
        $bytes = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/reports/ventas-medio-pago/excel?desde=2026-04-01&hasta=2026-04-30')
            ->assertStatus(200)
            ->streamedContent();

        $tmpPath = tempnam(sys_get_temp_dir(), 'vmp_xlsx_');
        file_put_contents($tmpPath, $bytes);
        try {
            $header = IOFactory::createReader('Xlsx')->load($tmpPath)
                ->getActiveSheet()->toArray()[0];
        } finally {
            @unlink($tmpPath);
        }

        // Fijas antes de Total.
        $this->assertSame(
            ['Fecha', 'Cliente', 'N° Documento', 'Base Imponible', 'ITBMS', 'Total'],
            array_slice($header, 0, 6),
        );
        // Métodos después de Total, en el orden nuevo.
        $this->assertSame(
            [
                'Transferencia', 'Cheque', 'Efectivo', 'Yappy', 'Link de pago',
                'Tarjeta', 'Depósito bancario', 'Retención de impuestos', 'Ajustes de cuenta',
            ],
            array_slice($header, 6, 9),
        );
    }

    // ------------------------------------------------- SCRUM-935 — formato de moneda ----

    public function test_935_excel_escribe_todas_las_columnas_de_dinero_como_numeros_con_formato_de_moneda(): void
    {
        $master = MasterClient::create(['name' => 'Cliente VMP 935']);
        $invoice = $this->makeInvoice($master->id, 500.0, 0, '2026-04-01 09:00:00');
        $this->makePayment($invoice, 500.0, AdminContPayment::METODO_YAPPY, '2026-04-01 09:00:00');

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->get('/api/admin-contab/reports/ventas-medio-pago/excel?desde=2026-04-01&hasta=2026-04-30')
            ->assertStatus(200);
        $sheet = $this->loadWorkbook($response)->getActiveSheet();

        $lastCol = Coordinate::stringFromColumnIndex(count($sheet->toArray()[0]));

        foreach ([2, 3] as $row) { // fila de documento y "Total del período"
            // Base Imponible (D), ITBMS (E), Total (F) y las 9 columnas de medio de pago (G…O).
            foreach (range(4, Coordinate::columnIndexFromString($lastCol)) as $colIdx) {
                $coord = Coordinate::stringFromColumnIndex($colIdx).$row;
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coord)->getDataType(), "{$coord} numérica");
                $this->assertSame(ExcelMoney::FORMAT, $sheet->getStyle($coord)->getNumberFormat()->getFormatCode(), "{$coord} formato");
            }
            $this->assertMoneyCell($sheet, "J{$row}", 500.0); // Yappy
        }
    }
}
