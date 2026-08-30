<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Batch 23 Grupo 3 de Admin&Cont (SCRUM-661→664, REQ-584→587) — "Libro de Facturas" (4M3). */
class AdminContInvoiceBookControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL    = 'conta@illuminations.com.pa';
    private const DESIGNER_EMAIL = 'designer@illuminations.test';

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

    private function makeRate(float $porcentaje): AdminContItbmsRate
    {
        return AdminContItbmsRate::create(['nombre' => 'Tasa '.self::uniqueTestNumber(), 'descripcion' => 'Tasa de prueba', 'porcentaje' => $porcentaje, 'activa' => true]);
    }

    /** Cadena real Order->pipelineCard->subClient para poder resolver RUC. */
    private function makeInvoiceWithRuc(float $monto, ?AdminContItbmsRate $rate, string $createdAt, string $taxId): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente Libro '.self::uniqueTestNumber()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Libro '.self::uniqueTestNumber(), 'tax_id' => $taxId]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Libro '.self::uniqueTestNumber()]);
        $card   = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userId(self::FELIX_EMAIL), 'amount' => $monto, 'stage_changed_at' => now(),
        ]);
        $order  = Order::create([
            'order_number' => 'PED-LIB-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        $invoice = AdminContInvoice::create([
            'numero' => 'F-LIB-'.self::uniqueTestNumber(), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'sales_project_id' => $project->id,
            'itbms_rate_id' => $rate?->id, 'monto' => $monto, 'saldo_aplicado' => 0,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    private function makeCreditNote(AdminContInvoice $factura, string $tipo, ?string $subtipo, float $monto, float $subtotal, float $itbms, string $fecha): AdminContCreditNote
    {
        return AdminContCreditNote::create([
            'numero' => 'NC-LIB-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id,
            'cliente' => $factura->cliente, 'tipo' => $tipo, 'subtipo_anulacion' => $subtipo, 'factura_origen_id' => $factura->id,
            'monto' => $monto, 'subtotal' => $subtotal, 'itbms' => $itbms, 'motivo' => 'Motivo de prueba',
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => $this->userId(self::FELIX_EMAIL), 'fecha' => $fecha,
        ]);
    }

    private function userId(string $email): int
    {
        return (int) \App\Models\User::where('email', $email)->value('id');
    }

    // ------------------------------------------------------------------- Gate ----

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas')->assertStatus(403);
    }

    // ------------------------------------------------------------------- Orden y % real ----

    public function test_documentos_en_orden_cronologico_con_porcentaje_real_por_documento(): void
    {
        $rate7  = $this->makeRate(7.0);
        $rate0  = $this->makeRate(0.0);

        // 8400 @ 7% -> base 7850.47, itbms 549.53 (mismo cálculo que el mockup real).
        $this->makeInvoiceWithRuc(8400.0, $rate7, '2026-02-01 09:00:00', '8-745-1230');
        // 2500 @ 0% (exento) -> base 2500, itbms 0.
        $this->makeInvoiceWithRuc(2500.0, $rate0, '2026-01-01 09:00:00', '155-336698-1-2024');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas')
            ->assertStatus(200);

        $docs = $response->json('documentos');
        $this->assertCount(2, $docs);
        // Enero antes que febrero.
        $this->assertSame('2026-01-01', $docs[0]['fecha']);
        $this->assertEquals(0.0, $docs[0]['porcentaje']);
        $this->assertEquals(2500.0, $docs[0]['base_imponible']);
        $this->assertSame('2026-02-01', $docs[1]['fecha']);
        $this->assertEquals(7.0, $docs[1]['porcentaje']);
        $this->assertEqualsWithDelta(7850.47, $docs[1]['base_imponible'], 0.01);
        $this->assertEqualsWithDelta(549.53, $docs[1]['itbms'], 0.01);
    }

    public function test_ruc_se_resuelve_desde_la_cadena_order_pipelinecard_subcliente(): void
    {
        $invoice = $this->makeInvoiceWithRuc(1000.0, null, '2026-03-01 09:00:00', '8-999-8888');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas')
            ->assertStatus(200);

        $this->assertSame('8-999-8888', $response->json('documentos.0.ruc'));
        $this->assertSame($invoice->cliente, $response->json('documentos.0.cliente'));
    }

    // ------------------------------------------------------------------- Notas en negativo ----

    public function test_nota_de_credito_aparece_en_negativo_con_su_motivo_real(): void
    {
        $factura = $this->makeInvoiceWithRuc(150.0, $this->makeRate(7.0), '2026-04-01 09:00:00', '155-1-1');
        $this->makeCreditNote($factura, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, null, 150.0, 140.19, 9.81, '2026-04-05');

        // 'nota_credito' — el vocabulario REAL que manda el frontend (InvoiceBookTipo, el `value`
        // de <option> en LibroFacturasPage.tsx), no la etiqueta de visualización 'Nota de Crédito'.
        // Bug real encontrado en Pre-QA (2026-08-27): este test antes usaba la etiqueta con acento
        // y pasaba igual porque coincidía con un `match` del controller que comparaba contra ESA
        // etiqueta en vez del vocabulario real — el filtro nunca funcionó para ningún valor que el
        // <select> del frontend pudiera enviar de verdad. Ver AdminContInvoiceBookController::build().
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas?tipo=nota_credito')
            ->assertStatus(200);

        $docs = $response->json('documentos');
        $this->assertCount(1, $docs);
        $this->assertSame('Descuento comercial', $docs[0]['motivo']);
        $this->assertEquals(-150.0, $docs[0]['total']);
        $this->assertEquals(-140.19, $docs[0]['base_imponible']);
        $this->assertEquals(-9.81, $docs[0]['itbms']);
    }

    public function test_filtro_tipo_factura_excluye_notas_de_credito(): void
    {
        $factura = $this->makeInvoiceWithRuc(150.0, $this->makeRate(7.0), '2026-04-01 09:00:00', '155-1-1');
        $this->makeCreditNote($factura, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, null, 150.0, 140.19, 9.81, '2026-04-05');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas?tipo=factura')
            ->assertStatus(200);

        $docs = $response->json('documentos');
        $this->assertCount(1, $docs);
        $this->assertSame('Factura', $docs[0]['tipo']);
    }

    public function test_resumen_neto_resta_las_notas_de_credito_no_las_suma_aparte(): void
    {
        $factura = $this->makeInvoiceWithRuc(1000.0, $this->makeRate(0.0), '2026-05-01 09:00:00', '155-1-1');
        $this->makeCreditNote($factura, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, null, 200.0, 200.0, 0.0, '2026-05-02');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas')
            ->assertStatus(200);

        $response->assertJson(['resumen' => ['facturas' => 1, 'notas_credito' => 1, 'total' => 800.0]]);
    }

    public function test_factura_anulada_no_aparece_en_el_libro(): void
    {
        $invoice = $this->makeInvoiceWithRuc(500.0, $this->makeRate(0.0), '2026-06-01 09:00:00', '155-1-1');
        $invoice->forceFill(['anulada_at' => now()])->save();

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('documentos'));
    }

    // ------------------------------------------------------------------- Export ----

    public function test_export_sin_documentos_devuelve_422(): void
    {
        $token = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/reports/libro-facturas/excel?desde=2099-01-01&hasta=2099-01-31')
            ->assertStatus(422);
    }

    public function test_export_con_datos_devuelve_xlsx(): void
    {
        $this->makeInvoiceWithRuc(500.0, $this->makeRate(7.0), '2026-07-01 09:00:00', '155-1-1');

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/reports/libro-facturas/excel?desde=2026-07-01&hasta=2026-07-31');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
