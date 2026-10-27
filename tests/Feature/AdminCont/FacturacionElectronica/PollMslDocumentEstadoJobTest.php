<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\PollMslDocumentEstadoJob;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\DocumentoAunPendienteException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 tarea 3 — `handle()` se llama directo (sin pasar por la cola real), pero ahora sí
 * toca BD/tenant (busca el modelo por serie+numero y persiste vía `FiscalCertificationService`) —
 * por eso pasa de Unit a Feature. Cubre la lógica de reintento/resolución (ya probada antes) MÁS
 * la persistencia real, nueva en este batch.
 */
final class PollMslDocumentEstadoJobTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
    }

    private function facturaPendiente(): AdminContInvoice
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        $factura = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $factura->forceFill(['serie' => 'ILU', 'fe_estado' => 'pendiente'])->save();

        return $factura->fresh();
    }

    private function job(AdminContInvoice $factura): PollMslDocumentEstadoJob
    {
        return new PollMslDocumentEstadoJob(
            serie: (string) $factura->serie,
            numero: $factura->numero,
            tipoDocumento: 'factura',
            tenantSlug: 'atlantic',
            numeroFiscal: $this->numeroFiscal($factura),
        );
    }

    // SCRUM-987 — el polling consulta a MSL por el numero puramente numérico que de verdad se
    // certificó, nunca por el folio interno "F-XXXX" (ver FiscalDocumentBuilder::numeroFiscal()).
    private function numeroFiscal(AdminContInvoice $factura): string
    {
        return app(FiscalDocumentBuilder::class)->numeroFiscal($factura->numero);
    }

    private function ejecutar(PollMslDocumentEstadoJob $job): void
    {
        $job->handle(app(PacProviderFactory::class), app(FiscalCertificationService::class));
    }

    public function test_lanza_excepcion_de_reintento_mientras_sigue_pendiente(): void
    {
        $factura = $this->facturaPendiente();
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$this->numeroFiscal($factura)}" => Http::response(['estado' => 'PENDIENTE'], 202)]);

        $this->expectException(DocumentoAunPendienteException::class);

        $this->ejecutar($this->job($factura));
    }

    public function test_no_lanza_excepcion_cuando_ya_esta_certificado_y_persiste_el_resultado(): void
    {
        $factura = $this->facturaPendiente();
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$this->numeroFiscal($factura)}" => Http::response([
            'estado' => 'CERTIFICADO', 'cufe' => 'FE01...resuelto', 'qr' => 'https://qr.test',
        ], 200)]);

        $this->ejecutar($this->job($factura));

        $fresh = $factura->fresh();
        $this->assertSame('certificado', $fresh->fe_estado);
        $this->assertSame('FE01...resuelto', $fresh->cufe);
        $this->assertNotNull($fresh->fe_certificado_at);
    }

    public function test_rechazado_tras_polling_tambien_se_persiste(): void
    {
        $factura = $this->facturaPendiente();
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$this->numeroFiscal($factura)}" => Http::response(['estado' => 'RECHAZADO', 'detalles' => ['RUC inválido']], 422)]);

        $this->ejecutar($this->job($factura));

        $this->assertSame('rechazado', $factura->fresh()->fe_estado);
        $this->assertNull($factura->fresh()->cufe);
    }

    /**
     * Senior Review 2026-09-20, finding #1: un error_configuracion detectado durante el polling
     * (ej. la API key se invalidó a mitad de la ventana de reintentos) NO puede quedar logueado
     * como "documento resuelto" — no lo es, el estado fiscal real sigue sin confirmarse. Tampoco se
     * pisa `fe_estado` (sigue en `pendiente`, más honesto que un estado que no resuelve nada).
     */
    public function test_error_configuracion_durante_polling_no_se_loguea_como_resuelto_ni_se_persiste(): void
    {
        $factura = $this->facturaPendiente();
        Log::spy();
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$this->numeroFiscal($factura)}" => Http::response(['ok' => false, 'error' => 'API key inválida'], 401)]);

        $this->ejecutar($this->job($factura)); // no debe lanzar excepción — no reintenta con config rota

        $this->assertSame('pendiente', $factura->fresh()->fe_estado, 'fe_estado no se pisa — sigue sin resolverse.');
        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'requiere intervención manual')
        );
    }

    public function test_pendiente_real_202_reintenta_sin_logear_warning_de_transporte(): void
    {
        $factura = $this->facturaPendiente();
        Log::spy();
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$this->numeroFiscal($factura)}" => Http::response(['estado' => 'PENDIENTE'], 202)]);

        try {
            $this->ejecutar($this->job($factura));
            $this->fail('Se esperaba DocumentoAunPendienteException.');
        } catch (DocumentoAunPendienteException) {
            // esperado — un 202 real también reintenta, ver docblock del job.
        }

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Senior Review 2026-09-17, finding #6: "MSL encoló el documento" (202 real) y "no pudimos ni
     * contactar a MSL" (circuito abierto) reintentan igual, pero deben dejar una señal distinta en
     * logs.
     */
    public function test_circuito_abierto_reintenta_igual_pero_logea_warning_de_transporte(): void
    {
        $factura = $this->facturaPendiente();
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());
        Log::spy();

        try {
            Http::fake(); // si el job llegara a llamar a MSL igual, esto lo delata.

            try {
                $this->ejecutar($this->job($factura));
                $this->fail('Se esperaba DocumentoAunPendienteException.');
            } catch (DocumentoAunPendienteException) {
                // esperado
            }

            Http::assertNothingSent();
            Log::shouldHaveReceived('warning')->once()->withArgs(
                fn (string $message) => str_contains($message, 'circuito abierto/timeout')
            );
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_tenant_invalido_se_descarta_sin_lanzar(): void
    {
        Log::spy();
        $job = new PollMslDocumentEstadoJob(serie: 'ILU', numero: 'F-9999', tipoDocumento: 'factura', tenantSlug: 'no-existe', numeroFiscal: '9999');

        $this->ejecutar($job);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'sin tenant válido')
        );
    }

    public function test_documento_no_encontrado_se_descarta_sin_lanzar(): void
    {
        Log::spy();
        $job = new PollMslDocumentEstadoJob(serie: 'ILU', numero: 'F-999999-no-existe', tipoDocumento: 'factura', tenantSlug: 'atlantic', numeroFiscal: '999999');

        $this->ejecutar($job);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'no encontró el documento')
        );
    }
}
