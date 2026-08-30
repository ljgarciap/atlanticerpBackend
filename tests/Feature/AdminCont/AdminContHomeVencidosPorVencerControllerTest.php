<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Tests\Feature\FeatureTestCase;

/**
 * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435), Grupo 4 (SCRUM-511,
 * REQ-434) — panel "Vencidos y por vencer". `InvoiceService::vencidosYPorVencer()` reusa
 * `cobrablesConVencimientoSigned()`, ya cubierto por los tests del Grupo 1/3 — acá solo se
 * verifica el filtrado/orden/borde propios de este ticket.
 */
class AdminContHomeVencidosPorVencerControllerTest extends FeatureTestCase
{
    private const FELIX_EMAIL    = 'conta@atlantic.com.pa';
    private const DESIGNER_EMAIL = 'designer@atlantic.test';
    private const URL            = '/api/admin-contab/home/vencidos-por-vencer';

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

    /**
     * `created_at` se fija para que la factura venza exactamente `diasHastaVencer` días desde el
     * "hoy" congelado con `travelTo()` (negativo = ya vencida hace esos días). Requiere que el test
     * llamante ya haya llamado `$this->travelTo(...)` antes — de lo contrario el drift de
     * microsegundos entre el `now()` de este helper y el `now()` real del request trunca el día
     * por redondeo hacia cero (`(int)` no hace floor) y desalinea el borde exacto que cada test
     * verifica.
     */
    private function makeInvoiceVenciendoEn(int $diasHastaVencer, float $monto = 1000.0, float $saldoAplicado = 0.0, bool $incobrable = false): AdminContInvoice
    {
        $diasCredito = AdminContFiscalSettings::current()->dias_credito_factura;
        $createdAt   = CarbonImmutable::now()->subDays($diasCredito - $diasHastaVencer);

        $invoice = AdminContInvoice::create([
            'numero' => 'F-VPV-'.self::uniqueTestNumber(), 'order_id' => self::uniqueTestNumber(),
            'cliente' => 'Cliente Vencidos Pend', 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
            'es_incobrable' => $incobrable,
        ]);
        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice;
    }

    public function test_vendedor_disenador_recibe_403(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(403);
    }

    public function test_sin_datos_devuelve_ambas_listas_vacias(): void
    {
        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);

        $response->assertJsonStructure(['vencidos', 'por_vencer']);
        $this->assertEquals([], $response->json('vencidos'));
        $this->assertEquals([], $response->json('por_vencer'));
    }

    /** Escenario 1 del ticket: vencida hace 12 días -> aparece en "vencidos" con dias_vencido: 12. */
    public function test_factura_vencida_hace_12_dias_aparece_en_vencidos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(-12, 6200.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertCount(1, $response->json('vencidos'));
        $this->assertEquals(12, $response->json('vencidos.0.dias_vencido'));
        $this->assertEquals(6200.0, $response->json('vencidos.0.monto'));
        $this->assertEquals([], $response->json('por_vencer'));
    }

    /** Escenario 2 del ticket: vence en 3 días -> aparece en "por_vencer" con dias_para_vencer: 3. */
    public function test_factura_que_vence_en_3_dias_aparece_en_por_vencer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(3, 9800.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertEquals([], $response->json('vencidos'));
        $this->assertCount(1, $response->json('por_vencer'));
        $this->assertEquals(3, $response->json('por_vencer.0.dias_para_vencer'));
        $this->assertEquals(9800.0, $response->json('por_vencer.0.monto'));
    }

    /** Borde "vence hoy" (decisión del Arquitecto): va en por_vencer con dias_para_vencer: 0, nunca en vencidos. */
    public function test_factura_que_vence_hoy_va_en_por_vencer_con_cero_dias(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertEquals([], $response->json('vencidos'));
        $this->assertCount(1, $response->json('por_vencer'));
        $this->assertEquals(0, $response->json('por_vencer.0.dias_para_vencer'));
    }

    /** Límite de la ventana: día 7 incluido en por_vencer, día 8 fuera de ambas listas. */
    public function test_ventana_de_7_dias_incluye_el_borde_y_excluye_el_dia_8(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(7, 100.0);
        $this->makeInvoiceVenciendoEn(8, 200.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertCount(1, $response->json('por_vencer'));
        $this->assertEquals(7, $response->json('por_vencer.0.dias_para_vencer'));
        $this->assertEquals([], $response->json('vencidos'));
    }

    /** RN4: orden de más urgente a menos urgente en cada sección. */
    public function test_orden_de_mas_urgente_a_menos_urgente(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(-3, 100.0);
        $this->makeInvoiceVenciendoEn(-20, 200.0);
        $this->makeInvoiceVenciendoEn(-9, 300.0);

        $this->makeInvoiceVenciendoEn(5, 400.0);
        $this->makeInvoiceVenciendoEn(1, 500.0);
        $this->makeInvoiceVenciendoEn(6, 600.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertEquals([20, 9, 3], collect($response->json('vencidos'))->pluck('dias_vencido')->all());
        $this->assertEquals([1, 5, 6], collect($response->json('por_vencer'))->pluck('dias_para_vencer')->all());
    }

    /** RN1/Escenario 3: factura incobrable no aparece en ninguna lista, aunque esté vencida. */
    public function test_factura_incobrable_no_aparece_en_ninguna_lista(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 12:00:00'));
        $this->makeInvoiceVenciendoEn(-12, 6200.0, incobrable: true);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))->getJson(self::URL)->assertStatus(200);
        $this->travelBack();

        $this->assertEquals([], $response->json('vencidos'));
        $this->assertEquals([], $response->json('por_vencer'));
    }
}
