<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\PollMslDocumentEstadoJob;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\Services\FeTransmisionRecorder;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-987 — registro append-only de cada transmisión al PAC (payload enviado + respuesta).
 * Motivo: MSL pidió los payloads de las pruebas con falla y no existían guardados.
 */
final class FeTransmisionesTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;

    private const MARK_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
    }

    private function service(): FiscalCertificationService
    {
        return app(FiscalCertificationService::class);
    }

    private function facturaSimple(): AdminContInvoice
    {
        // Se crea con el interruptor en Apagado para que el listener de creación (InvoiceCreated) no
        // certifique por su cuenta: cada test dispara la certificación explícitamente y cuenta
        // exactamente las transmisiones que ese disparo produce.
        $ambiente = AdminContFiscalSettings::current()->pac_ambiente;
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();

        ['root' => $root] = $this->makePedido([['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => $ambiente])->save();

        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
    }

    public function test_certificacion_rechazada_guarda_el_payload_exacto_y_la_respuesta(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO', 'error' => 'Método: VALIDATE_B07a',
            'detalles' => ['9026 - Falló la validación de algunos escenarios.'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $t = AdminContFeTransmision::query()->sole();
        $this->assertSame('factura', $t->documento_tipo);
        $this->assertSame($factura->id, $t->documento_id);
        $this->assertSame('msl', $t->provider);
        $this->assertSame('pruebas', $t->ambiente);
        $this->assertSame('certificar', $t->operacion);
        $this->assertSame('certificacion', $t->origen);
        $this->assertSame('/v1/facturas', $t->endpoint);
        $this->assertSame('rechazado', $t->resultado);
        $this->assertSame(422, $t->http_status);
        $this->assertNotNull($t->duracion_ms);
        $this->assertSame('ILU', $t->request_payload['serie']);
        $this->assertSame($t->numero_fiscal, $t->request_payload['numero']);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $t->request_payload['numero']); // SCRUM-987: puramente numérico
        $this->assertSame('pruebas', $t->request_payload['ambiente']);
        $this->assertSame('Método: VALIDATE_B07a', $t->response_body['error']);
        $this->assertSame(['9026 - Falló la validación de algunos escenarios.'], $t->response_body['detalles']);
    }

    public function test_la_respuesta_guardada_no_incluye_el_xml_firmado(): void
    {
        Storage::fake('s3-private');
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...x', 'qr' => 'https://qr.test',
            'xmlFirmado' => base64_encode('<xml>enorme</xml>'),
        ], 200)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $t = AdminContFeTransmision::query()->sole();
        $this->assertSame('certificado', $t->resultado);
        $this->assertSame('FE01...x', $t->response_body['cufe']);
        $this->assertArrayNotHasKey('xmlFirmado', $t->response_body);
        $this->assertNotNull($factura->fresh()->xml_firmado_path); // el XML sí sigue archivado en S3
    }

    public function test_apagado_no_registra_ninguna_transmision(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        Http::fake();
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $this->assertSame(0, AdminContFeTransmision::query()->count());
        Http::assertNothingSent();
    }

    public function test_circuito_abierto_registra_sin_respuesta_con_el_payload_que_no_se_pudo_enviar(): void
    {
        Queue::fake(); // 'pendiente' agenda el polling; con cola síncrona correría en el acto
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());

        try {
            Http::fake();
            $factura = $this->facturaSimple();

            $this->service()->certificarFactura($factura);

            $t = AdminContFeTransmision::query()->sole();
            $this->assertSame('sin_respuesta', $t->resultado);
            $this->assertNull($t->http_status);
            $this->assertNotNull($t->request_payload);
            // SCRUM-1140 (A1): una factura creada con el PAC apagado revalida su receptor (GET validar-ruc) antes de enviar;
            // lo que no debe salir es el POST del documento, porque el circuito de certificación está abierto.
            Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_la_api_key_se_redacta_en_la_respuesta_guardada(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO', 'error' => 'llave usada: sandbox-key', 'detalles' => ['x'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $guardado = json_encode(AdminContFeTransmision::query()->sole()->response_body);
        $this->assertStringNotContainsString('sandbox-key', (string) $guardado);
        $this->assertStringContainsString('[redactado]', (string) $guardado);
    }

    public function test_un_cuerpo_de_mas_de_64kb_se_trunca_y_se_marca(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response([
            'ok' => false, 'estado' => 'RECHAZADO', 'error' => str_repeat('z', 100000), 'detalles' => ['x'],
        ], 422)]);
        $factura = $this->facturaSimple();

        $this->service()->certificarFactura($factura);

        $body = AdminContFeTransmision::query()->sole()->response_body;
        $this->assertTrue($body['_truncado']);
        $this->assertLessThanOrEqual(65536, strlen($body['contenido_parcial']));
    }

    public function test_un_fallo_al_registrar_nunca_bloquea_la_certificacion(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9026 - x']], 422)]);
        $factura = $this->facturaSimple();
        AdminContFeTransmision::creating(static fn () => throw new \RuntimeException('disco lleno'));

        $this->service()->certificarFactura($factura);

        $factura = $factura->fresh();
        $this->assertSame('rechazado', $factura->fe_estado);
        $this->assertStringContainsString('9026', (string) $factura->fe_detalle);
        $this->assertSame(0, AdminContFeTransmision::query()->count());
    }

    public function test_cada_consulta_de_polling_deja_su_registro_con_request_nulo(): void
    {
        Queue::fake();
        Http::fake(['sandbox.msl.test/v1/facturas' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202)]);
        $factura = $this->facturaSimple();
        $this->service()->certificarFactura($factura);
        $factura = $factura->fresh();
        $numeroFiscal = (string) AdminContFeTransmision::query()->sole()->numero_fiscal;

        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$numeroFiscal}" => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...poll', 'qr' => 'https://qr.test'], 200)]);
        (new PollMslDocumentEstadoJob('ILU', $factura->numero, 'factura', 'atlantic', $numeroFiscal))
            ->handle(app(PacProviderFactory::class), $this->service());

        $this->assertSame(2, AdminContFeTransmision::query()->count());
        $poll = AdminContFeTransmision::query()->where('origen', 'polling')->sole();
        $this->assertSame('consultar_estado', $poll->operacion);
        $this->assertNull($poll->request_payload);
        $this->assertSame(200, $poll->http_status);
        $this->assertSame('certificado', $poll->resultado);
        $this->assertSame("/v1/documentos/ILU/{$numeroFiscal}", $poll->endpoint);
    }

    public function test_un_fallo_real_de_sql_no_loguea_la_pii_del_receptor_ni_aborta_la_transaccion(): void
    {
        $factura = $this->facturaSimple();
        Log::spy();
        $resultado = new FiscalDocumentResult(
            estado: 'rechazado', pacProvider: 'msl', rawResponse: ['x' => 1],
            requestPayload: ['receptor' => ['ruc' => 'RUC-SECRETO-123', 'nombre' => 'NOMBRE-SECRETO', 'email' => 'secreto@cliente.test']],
            httpStatus: 422, endpoint: '/v1/facturas', ambiente: 'pruebas',
        );

        // `documento_tipo` es varchar(20): 30 caracteres provocan una QueryException REAL de Postgres,
        // cuyo mensaje trae el SQL con todos los valores interpolados (incluido el payload).
        app(FeTransmisionRecorder::class)->registrar($factura, str_repeat('x', 30), $resultado, 'certificacion', 'ILU', '0001');

        Log::shouldHaveReceived('error')->withArgs(function (string $msg, array $ctx): bool {
            $todo = $msg.json_encode($ctx);

            return str_contains($msg, 'no se pudo registrar la transmisión')
                && ! str_contains($todo, 'RUC-SECRETO') && ! str_contains($todo, 'NOMBRE-SECRETO') && ! str_contains($todo, 'secreto@cliente.test')
                && isset($ctx['exception_class']);
        })->once();

        // Dentro de una transacción abierta (la del test), el error SQL solo abortó un savepoint:
        // la transacción sigue viva y se puede seguir consultando y escribiendo.
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
        $factura->forceFill(['fe_estado' => 'rechazado'])->save();
        $this->assertSame('rechazado', $factura->fresh()->fe_estado);
    }

    public function test_un_byte_nul_en_la_respuesta_no_pierde_el_registro(): void
    {
        $factura = $this->facturaSimple();
        $resultado = new FiscalDocumentResult(
            estado: 'rechazado', pacProvider: 'msl', rawResponse: ['error' => "texto\0con nul"],
            requestPayload: ['descripcion' => "linea\0con nul"], httpStatus: 422, endpoint: '/v1/facturas', ambiente: 'pruebas',
        );

        app(FeTransmisionRecorder::class)->registrar($factura, 'factura', $resultado, 'certificacion', 'ILU', '0001');

        $t = AdminContFeTransmision::query()->sole();
        $this->assertSame('textocon nul', $t->response_body['error']);
        $this->assertSame('lineacon nul', $t->request_payload['descripcion']);
    }

    public function test_una_barra_invertida_literal_seguida_de_u0000_no_invalida_el_json_guardado(): void
    {
        $factura = $this->facturaSimple();
        $resultado = new FiscalDocumentResult(
            estado: 'rechazado', pacProvider: 'msl', rawResponse: ['error' => 'ruta C:\\u0000dir y otra'],
            requestPayload: ['descripcion' => 'ok'], httpStatus: 422, endpoint: '/v1/facturas', ambiente: 'pruebas',
        );

        app(FeTransmisionRecorder::class)->registrar($factura, 'factura', $resultado, 'certificacion', 'ILU', '0001');

        // El texto legítimo (barra + u0000 como caracteres) se conserva intacto y el registro existe.
        $this->assertSame('ruta C:\\u0000dir y otra', AdminContFeTransmision::query()->sole()->response_body['error']);
    }

    public function test_el_xml_que_digifact_manda_en_responsedata1_tampoco_se_guarda(): void
    {
        $factura = $this->facturaSimple();
        $resultado = new FiscalDocumentResult(
            estado: 'certificado', pacProvider: 'digifact', rawResponse: ['authNumber' => 'A1', 'responseData1' => 'PD94bWw=', 'ResponseData1' => 'PD94bWw='],
        );

        app(FeTransmisionRecorder::class)->registrar($factura, 'factura', $resultado, 'certificacion', 'ILU', '0001');

        $body = AdminContFeTransmision::query()->sole()->response_body;
        $this->assertSame('A1', $body['authNumber']);
        $this->assertArrayNotHasKey('responseData1', $body);
        $this->assertArrayNotHasKey('ResponseData1', $body);
    }

    public function test_una_nota_de_credito_certificada_de_verdad_deja_su_transmision_y_el_endpoint_la_devuelve(): void
    {
        Storage::fake('s3-private');
        $factura = $this->facturaSimple();
        $factura->forceFill(['serie' => 'ILU', 'fe_estado' => 'certificado', 'cufe' => 'FE0120000...origen'])->save();
        $note = AdminContCreditNote::create([
            'numero' => 'NC-REAL-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);
        Http::fake(['sandbox.msl.test/v1/notas-credito' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0420000...nc', 'qr' => 'https://qr.test'], 200)]);

        $this->service()->certificarNotaCredito($note);

        $t = AdminContFeTransmision::query()->sole();
        $this->assertSame('nota_credito', $t->documento_tipo);
        $this->assertSame($note->id, $t->documento_id);
        $this->assertSame('/v1/notas-credito', $t->endpoint);
        $this->assertSame('certificado', $t->resultado);
        $this->assertSame('FE0120000...origen', $t->request_payload['referencia']['cufe']);

        $this->api($this->loginAsMark(), 'GET', "/api/admin-contab/notas-credito/{$note->id}/fe-transmisiones")
            ->assertStatus(200)
            ->assertJsonPath('data.0.documento_id', null) // el endpoint no expone ids internos de documento
            ->assertJsonPath('data.0.endpoint', '/v1/notas-credito');
    }

    public function test_el_registro_es_append_only(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['x']], 422)]);
        $factura = $this->facturaSimple();
        $this->service()->certificarFactura($factura);
        $t = AdminContFeTransmision::query()->sole();

        try {
            $t->update(['resultado' => 'certificado']);
            $this->fail('debió rechazar la edición');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(\LogicException::class);
        $t->delete();
    }

    public function test_endpoint_de_factura_solo_para_mark_y_devuelve_el_historial(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9026 - x']], 422)]);
        $factura = $this->facturaSimple();
        $this->service()->certificarFactura($factura);
        $url = "/api/admin-contab/invoices/{$factura->order_id}/fe-transmisiones?invoice_id={$factura->id}";

        $this->api($this->token(), 'GET', $url)->assertStatus(403); // Felix

        $r = $this->api($this->loginAsMark(), 'GET', $url)->assertStatus(200);
        $r->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.resultado', 'rechazado');
        $r->assertJsonPath('data.0.http_status', 422);
        $r->assertJsonPath('data.0.request_payload.serie', 'ILU');
        $r->assertJsonPath('data.0.response_body.detalles.0', '9026 - x');
    }

    public function test_endpoint_de_nota_de_credito_solo_para_mark(): void
    {
        $factura = $this->facturaSimple();
        $note = AdminContCreditNote::create([
            'numero' => 'NC-TRANS-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);
        AdminContFeTransmision::create([
            'documento_tipo' => 'nota_credito', 'documento_id' => $note->id, 'provider' => 'msl', 'operacion' => 'certificar',
            'origen' => 'certificacion', 'resultado' => 'rechazado', 'http_status' => 422,
        ]);
        $url = "/api/admin-contab/notas-credito/{$note->id}/fe-transmisiones";

        $this->api($this->token(), 'GET', $url)->assertStatus(403);
        $this->api($this->loginAsMark(), 'GET', $url)->assertStatus(200)->assertJsonCount(1, 'data');
        $this->api($this->loginAsMark(), 'GET', '/api/admin-contab/notas-credito/999999/fe-transmisiones')->assertStatus(404);
    }
}
