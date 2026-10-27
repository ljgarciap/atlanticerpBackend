<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\PollMslDocumentEstadoJob;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — wiring real: certifica una factura, maneja los 4 estados posibles y persiste el
 * resultado. Regla dura cubierta explícitamente: ningún error de facturación electrónica revierte
 * ni bloquea la factura ya creada en AtlanticERP (siempre queda `error_configuracion`, nunca una
 * excepción sin atrapar).
 */
final class FiscalCertificationServiceTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();

        config([
            'services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'prod-key'],
        ]);
    }

    private function service(): FiscalCertificationService
    {
        return app(FiscalCertificationService::class);
    }

    private function habilitarMsl(string $ambiente = 'testing'): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => $ambiente])->save();
    }

    private function facturaSimple(): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
    }

    /** Fase 6b — un país sin paquete fiscal (`pac_provider = none`) emite documentos internos: nada viaja a ningún PAC. */
    public function test_proveedor_none_no_llama_a_ningun_pac_y_marca_no_aplica(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'none', 'pac_ambiente' => 'testing'])->save();
        Http::fake();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        Http::assertNothingSent();
        $this->assertSame('no_aplica', $factura->fresh()->fe_estado);
    }

    public function test_ambiente_apagado_no_llama_a_msl_y_marca_no_aplica(): void
    {
        $this->habilitarMsl('testing');
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        Http::fake();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        Http::assertNothingSent();
        $this->assertSame('no_aplica', $factura->fresh()->fe_estado);
        $this->assertNull($factura->fresh()->cufe);
    }

    public function test_doc_factura_deshabilitado_no_llama_a_msl(): void
    {
        $this->habilitarMsl('testing');
        AdminContFiscalSettings::current()->forceFill(['pac_doc_factura_habilitado' => false])->save();
        Http::fake();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        Http::assertNothingSent();
        $this->assertSame('no_aplica', $factura->fresh()->fe_estado);
    }

    public function test_certificado_persiste_cufe_qr_serie_y_archiva_el_xml(): void
    {
        $this->habilitarMsl('testing');
        Storage::fake('s3-private');
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0120000...fake',
            'qr' => 'https://dgi-fep-test.mef.gob.pa/qr', 'xmlFirmado' => base64_encode('<xml>fake</xml>'),
        ], 200)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);
        $factura = $factura->fresh();

        $this->assertSame('certificado', $factura->fe_estado);
        $this->assertSame('FE0120000...fake', $factura->cufe);
        $this->assertSame('https://dgi-fep-test.mef.gob.pa/qr', $factura->qr_content);
        $this->assertSame('ILU', $factura->serie);
        $this->assertSame('msl', $factura->pac_provider_usado);
        $this->assertNotNull($factura->fe_certificado_at);
        $this->assertNotNull($factura->xml_firmado_path);
        Storage::disk('s3-private')->assertExists($factura->xml_firmado_path);
        $this->assertSame('<xml>fake</xml>', Storage::disk('s3-private')->get($factura->xml_firmado_path));
    }

    public function test_rechazado_no_persiste_cufe(): void
    {
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['RUC inválido']], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);
        $factura = $factura->fresh();

        $this->assertSame('rechazado', $factura->fe_estado);
        $this->assertNull($factura->cufe);
        $this->assertNull($factura->fe_certificado_at);
    }

    public function test_rechazado_loguea_el_detalle_largo_de_msl_ademas_del_mensaje_corto(): void
    {
        $this->habilitarMsl('testing');
        $detalleLargo = "****** Mensaje ******\nSugerencia: Verifique la sección de EventosConsistencia.\nMétodo: VALIDATE_B07a";
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false,
            'estado' => 'RECHAZADO',
            'error' => $detalleLargo,
            'detalles' => ['9026 - Falló la validación de algunos escenarios.'],
        ], 422)]);
        Log::spy();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $ctx): bool => $msg === 'Facturación electrónica: documento no quedó certificado'
                && $ctx['errores'] === ['9026 - Falló la validación de algunos escenarios.']
                && $ctx['detalle_msl'] === $detalleLargo,
        );
    }

    public function test_rechazado_persiste_fe_detalle_con_codigo_y_regla(): void
    {
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false,
            'estado' => 'RECHAZADO',
            'error' => 'Método: VALIDATE_B07a',
            'detalles' => ['9026 - Falló la validación de algunos escenarios.'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $detalle = (string) $factura->fresh()->fe_detalle;
        $this->assertStringContainsString('9026 - Falló la validación de algunos escenarios.', $detalle);
        $this->assertStringContainsString('VALIDATE_B07a', $detalle);
    }

    public function test_recertificacion_exitosa_limpia_fe_detalle(): void
    {
        $this->habilitarMsl('testing');
        Storage::fake('s3-private');
        $factura = $this->facturaSimple();
        $factura->forceFill(['fe_estado' => 'rechazado', 'fe_detalle' => '9026 - viejo'])->save();
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0120000...fake', 'qr' => 'https://dgi.test/qr',
        ], 200)]);

        $this->service()->certificarFactura($factura->fresh());

        $factura = $factura->fresh();
        $this->assertSame('certificado', $factura->fe_estado);
        $this->assertNull($factura->fe_detalle);
    }

    public function test_fe_detalle_se_trunca_a_2000_caracteres_y_no_incluye_la_api_key(): void
    {
        $this->habilitarMsl('testing');
        // `fe_detalle` = "9" + "\n" + error → la API key (11 chars) queda en la posición absoluta 1995,
        // a caballo del corte de 2000. Con el orden viejo (truncar el `error` a 2000 ANTES de
        // redactar) queda un pedazo "sandb" que str_replace ya no reconoce; con el orden correcto
        // (redactar y recién después truncar) no queda ni un carácter de la key.
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false,
            'estado' => 'RECHAZADO',
            'error' => str_repeat('x', 1993).'sandbox-key'.str_repeat('y', 3000),
            'detalles' => ['9'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $detalle = (string) $factura->fresh()->fe_detalle;
        $this->assertSame(2000, mb_strlen($detalle));
        $this->assertStringNotContainsString('sandb', $detalle);
        $this->assertStringNotContainsString('sandbox-key', $detalle);
        $this->assertStringEndsWith('[reda', $detalle); // la redacción ocurrió antes del corte
    }

    public function test_error_configuracion_persiste_el_motivo_en_fe_detalle(): void
    {
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'error' => 'API key inválida'], 401)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);
        $factura = $factura->fresh();

        $this->assertSame('error_configuracion', $factura->fe_estado);
        $this->assertNotEmpty($factura->fe_detalle);
    }

    /**
     * Guarda de la opción A (2026-10-05): una factura con la tasa «3.5% — Retención de impuesto» NO llega a MSL
     * (que la certificaría como 7% con un total distinto al de AtlanticERP). Queda `error_configuracion` con el motivo
     * visible; la factura en AtlanticERP no se bloquea ni se revierte.
     */
    public function test_factura_con_tasa_de_retencion_no_se_envia_a_msl_y_queda_con_motivo_claro(): void
    {
        $this->habilitarMsl('testing');
        Http::fake();
        $factura = $this->facturaSimple();
        $tasa = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->firstOrFail();
        AdminContInvoice::whereKey($factura->id)->update(['itbms_rate_id' => $tasa->id]);
        $factura->lines()->update(['itbms_rate_id' => $tasa->id]);

        $enviosPrevios = count(Http::recorded()); // la creación de la factura ya certificó una vez con la tasa original

        $this->service()->certificarFactura($factura->fresh());
        $factura = $factura->fresh();

        $this->assertCount($enviosPrevios, Http::recorded(), 'Ninguna request nueva a MSL.');
        $this->assertSame('error_configuracion', $factura->fe_estado);
        $this->assertNull($factura->cufe);
        $this->assertStringContainsString('Retención de impuesto', (string) $factura->fe_detalle);
        $this->assertNull($factura->anulada_at, 'La factura en AtlanticERP sigue vigente.');
    }

    public function test_pendiente_202_deja_fe_detalle_en_null(): void
    {
        Queue::fake();
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202)]);
        $factura = $this->facturaSimple();
        $factura->forceFill(['fe_estado' => 'rechazado', 'fe_detalle' => '9026 - motivo viejo'])->save();

        $this->service()->certificarFactura($factura->fresh());

        $this->assertNull($factura->fresh()->fe_detalle);
    }

    public function test_pendiente_202_persiste_fe_estado_pendiente_y_agenda_el_polling(): void
    {
        Queue::fake();
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $this->assertSame('pendiente', $factura->fresh()->fe_estado);
        Queue::assertPushed(
            PollMslDocumentEstadoJob::class,
            // SCRUM-987 — numeroFiscal debe ser el numero puramente numérico que de verdad se
            // mandó al PAC, nunca el folio interno "F-XXXX".
            fn ($job) => $job->serie === 'ILU' && $job->numero === $factura->numero
                && $job->numeroFiscal === app(FiscalDocumentBuilder::class)->numeroFiscal($factura->numero)
                && $job->tipoDocumento === 'factura' && $job->tenantSlug === 'atlantic',
        );
    }

    public function test_401_de_msl_persiste_error_configuracion_no_rechazado(): void
    {
        $this->habilitarMsl('testing');
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'error' => 'API key inválida'], 401)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);
        $factura = $factura->fresh();

        $this->assertSame('error_configuracion', $factura->fe_estado);
        $this->assertNotSame('rechazado', $factura->fe_estado);
    }

    /** Un pac_provider que no existe en el registro — PacProviderFactory lanza, se atrapa acá. */
    public function test_provider_no_wireado_nunca_rompe_persiste_error_configuracion(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'proveedor-inexistente'])->save();
        Http::fake();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        Http::assertNothingSent();
        $this->assertSame('error_configuracion', $factura->fresh()->fe_estado);
    }
}
