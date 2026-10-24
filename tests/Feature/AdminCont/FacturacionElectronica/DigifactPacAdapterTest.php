<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\DigifactPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\DigifactTokenService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Extiende `Tests\TestCase` (sin DB/tenant) — todo mockeado con Http::fake(), nunca llama a
 * testnucpa.digifact.com de verdad. Cubre la normalización de estado (la pieza de mayor
 * incertidumbre del adapter, ver docblock de DigifactPacAdapter) y el retry ante 401.
 */
final class DigifactPacAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.digifact.base_url_pruebas' => 'https://testnucpa.digifact.com/api',
            'services.digifact.ruc' => '155704849-2-2021',
            'services.digifact.username' => 'digifact-test-user',
            'services.digifact.password' => 'digifact-test-pass',
        ]);
    }

    private function documento(): FiscalDocument
    {
        return new FiscalDocument(
            tipoDocumento: 'factura',
            serie: 'ILU',
            numero: '1001',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILLUMINATION S.A.'),
            receptor: FiscalParty::consumidorFinal(),
            items: [new FiscalDocumentItem(codigo: 'P1', descripcion: 'Lámpara LED', cantidad: 1, precioUnitario: 18.50, tasaItbmsCode: '01')],
            pagos: [new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 19.80)],
        );
    }

    public function test_certificar_factura_exitosa_se_normaliza_a_certificado(): void
    {
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'jwt-de-prueba'], 200),
            '*/v2/transform/nuc_json' => Http::response(['authNumber' => 'FE0120000155704849...'], 200),
        ]);

        $adapter = new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas');
        $resultado = $adapter->certificarFactura($this->documento());

        $this->assertTrue($resultado->certificado());
        $this->assertSame('digifact', $resultado->pacProvider);
        $this->assertSame('FE0120000155704849...', $resultado->cufe);
    }

    public function test_rechazo_se_normaliza_con_errores(): void
    {
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'jwt-de-prueba'], 200),
            '*/v2/transform/nuc_json' => Http::response([
                'status' => 'ERROR_AL_ENVIAR',
                'infoDetails' => [['dMsgRes' => 'RUC del receptor inválido']],
            ], 422),
        ]);

        $adapter = new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas');
        $resultado = $adapter->certificarFactura($this->documento());

        $this->assertSame('rechazado', $resultado->estado);
        $this->assertSame(['RUC del receptor inválido'], $resultado->errores);
    }

    public function test_en_cola_se_normaliza_a_pendiente(): void
    {
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'jwt-de-prueba'], 200),
            '*/v2/transform/nuc_json' => Http::response(['status' => 'EN_COLA_DE_ENVIO'], 202),
        ]);

        $adapter = new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas');
        $resultado = $adapter->certificarFactura($this->documento());

        $this->assertSame('pendiente', $resultado->estado);
    }

    public function test_401_refresca_el_token_y_reintenta_una_vez(): void
    {
        Http::fake([
            '*/login/get_token' => Http::sequence()
                ->push(['token' => 'jwt-vencido'], 200)
                ->push(['token' => 'jwt-nuevo'], 200),
            '*/v2/transform/nuc_json' => Http::sequence()
                ->push([], 401)
                ->push(['authNumber' => 'FE01...'], 200),
        ]);

        $adapter = new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas');
        $resultado = $adapter->certificarFactura($this->documento());

        $this->assertTrue($resultado->certificado());
        Http::assertSentCount(4); // token, 401, refresh token, retry exitoso
    }

    public function test_circuito_ya_abierto_degrada_sin_llamar_a_digifact(): void
    {
        // Mismo patrón que tests/Feature/CRM/DocumentControllerTest.php para 'cb:s3-private'.
        Redis::set('cb:digifact-transform:opened_at', (string) time());

        try {
            Http::fake(); // si el adapter llegara a llamar a Digifact igual, esto lo delata.

            $resultado = (new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas'))
                ->certificarFactura($this->documento());

            $this->assertFalse($resultado->certificado());
            $this->assertStringContainsString('no está disponible', $resultado->errores[0] ?? '');
            Http::assertNothingSent();
        } finally {
            Redis::del('cb:digifact-transform:opened_at');
            Redis::del('cb:digifact-transform:failures');
        }
    }

    public function test_401_persistente_degrada_sin_excepcion_cruda(): void
    {
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'jwt-siempre-invalido'], 200),
            '*/v2/transform/nuc_json' => Http::response([], 401),
        ]);

        $adapter = new DigifactPacAdapter(new NucJsonMapper(), new DigifactTokenService(), 'pruebas');
        $resultado = $adapter->certificarFactura($this->documento());

        $this->assertFalse($resultado->certificado());
        $this->assertStringContainsString('credenciales', $resultado->errores[0] ?? '');
    }
}
