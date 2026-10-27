<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Sin base de datos a propósito (mismo criterio pedido para este batch) — MslPacAdapter no toca
 * Eloquent/DB en ningún método, solo Http::fake(). Extiende Tests\TestCase (no PHPUnit puro)
 * porque Http::fake()/config() necesitan el container de Laravel booteado.
 */
final class MslPacAdapterTest extends TestCase
{
    private function adapter(): MslPacAdapter
    {
        return new MslPacAdapter(baseUrl: 'https://fedigifact.test', apiKey: 'fake-key', ambiente: 'pruebas');
    }

    private function documento(): FiscalDocument
    {
        return new FiscalDocument(
            tipoDocumento: 'factura',
            serie: 'ILU',
            numero: '1001',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILUMINATIONS S.A.'),
            receptor: FiscalParty::consumidorFinal(),
            items: [
                new FiscalDocumentItem(
                    codigo: 'P1',
                    descripcion: 'Producto XL',
                    cantidad: 2,
                    precioUnitario: 18.50,
                    tasaItbmsCode: '01',
                ),
            ],
            pagos: [
                new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 39.59),
            ],
        );
    }

    public function test_certificar_factura_mapea_respuesta_certificado(): void
    {
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response([
                'ok' => true,
                'estado' => 'CERTIFICADO',
                'cufe' => 'FE0120000155704849-2-2021-...',
                'qr' => 'https://dgi-fep-test.mef.gob.pa:40001/...',
                'xmlFirmado' => 'base64==',
            ], 200),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertTrue($resultado->certificado());
        $this->assertSame('msl', $resultado->pacProvider);
        $this->assertSame('FE0120000155704849-2-2021-...', $resultado->cufe);
        $this->assertSame([], $resultado->errores);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://fedigifact.test/v1/facturas'
                && $request->hasHeader('x-api-key', 'fake-key')
                && $body['ambiente'] === 'pruebas'
                && $body['serie'] === 'ILU'
                && $body['numero'] === '1001'
                && $body['receptor']['tipo'] === '02'
                && $body['items'][0]['codigo'] === 'P1'
                && $body['items'][0]['tasaITBMS'] === '01'
                && $body['pagos'][0]['tipo'] === '02'; // efectivo (corregido SCRUM-943, ver MslPaymentMethodMapper)
        });
    }

    public function test_certificar_factura_mapea_respuesta_rechazado(): void
    {
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response([
                'ok' => false,
                'estado' => 'RECHAZADO',
                'detalles' => ['RUC del receptor no existe'],
            ], 422),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('rechazado', $resultado->estado);
        $this->assertNull($resultado->cufe);
        $this->assertSame(['RUC del receptor no existe'], $resultado->errores);
    }

    public function test_certificar_factura_mapea_respuesta_pendiente(): void
    {
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('pendiente', $resultado->estado);
        $this->assertFalse($resultado->errorTransporte); // 202 real de MSL, no una falla de transporte
    }

    /**
     * Senior Review 2026-09-17, finding #5: un 401 (API key inválida, error nuestro) NO puede
     * mapearse igual que un 422 (rechazo fiscal real de la DGI) — la UI necesita poder
     * distinguirlos para no decirle a un usuario "tu factura fue rechazada" cuando el problema es
     * que AtlanticERP está mal configurado.
     */
    public function test_certificar_factura_mapea_401_como_error_de_configuracion_no_como_rechazo(): void
    {
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response(['ok' => false, 'error' => 'API key inválida'], 401),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('error_configuracion', $resultado->estado);
        $this->assertFalse($resultado->certificado());
        $this->assertNotSame('rechazado', $resultado->estado);
        $this->assertTrue($resultado->errorConfiguracion());
        $this->assertStringContainsString('401', $resultado->errores[0] ?? '');
        $this->assertStringContainsString('no un rechazo de la DGI', $resultado->errores[0] ?? '');
    }

    public function test_certificar_factura_mapea_403_como_error_de_configuracion(): void
    {
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response(['ok' => false, 'error' => 'Ambiente no habilitado'], 403),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('error_configuracion', $resultado->estado);
        $this->assertStringContainsString('403', $resultado->errores[0] ?? '');
    }

    public function test_certificar_factura_422_real_sigue_mapeando_a_rechazado(): void
    {
        // Control: confirma que el fix de 401/403 no diluye el caso real de rechazo (422).
        Http::fake([
            'fedigifact.test/v1/facturas' => Http::response(['estado' => 'RECHAZADO', 'detalles' => ['RUC inválido']], 422),
        ]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('rechazado', $resultado->estado);
        $this->assertFalse($resultado->errorConfiguracion());
    }

    public function test_error_de_servidor_abre_circuito_y_no_se_reporta_como_rechazado(): void
    {
        Http::fake(['fedigifact.test/v1/facturas' => Http::response('boom', 500)]);

        $resultado = $this->adapter()->certificarFactura($this->documento());

        // No certificado, y el mensaje debe indicar indisponibilidad, no un rechazo fiscal real.
        $this->assertFalse($resultado->certificado());
        $this->assertStringContainsString('no está disponible', $resultado->errores[0] ?? '');
        $this->assertTrue($resultado->errorTransporte); // finding #6 — distinto de un 202 real
    }

    public function test_circuito_ya_abierto_degrada_sin_llamar_a_msl(): void
    {
        // Mismo patrón que tests/Feature/CRM/DocumentControllerTest.php para 'cb:s3-private' —
        // fuerza el circuito abierto directo en Redis en vez de gastar 5 fallas reales.
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());

        try {
            Http::fake(); // si el adapter llegara a llamar a MSL igual, esto lo delata.

            $resultado = $this->adapter()->certificarFactura($this->documento());

            $this->assertFalse($resultado->certificado());
            $this->assertStringContainsString('no está disponible', $resultado->errores[0] ?? '');
            $this->assertTrue($resultado->errorTransporte); // finding #6
            Http::assertNothingSent();
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_circuito_abierto_de_pruebas_no_afecta_al_adapter_de_produccion(): void
    {
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());

        try {
            Http::fake(['fedigifact-prod.test/v1/facturas' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...'], 200)]);
            $produccion = new MslPacAdapter(baseUrl: 'https://fedigifact-prod.test', apiKey: 'prod-key', ambiente: 'produccion');

            $this->assertTrue($produccion->certificarFactura($this->documento())->certificado());

            // Y el de pruebas sigue degradado por su propio circuito abierto.
            $this->assertTrue($this->adapter()->certificarFactura($this->documento())->errorTransporte);
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_certificar_nota_credito_incluye_referencia(): void
    {
        Http::fake(['fedigifact.test/v1/notas-credito' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE04...'], 200)]);

        $documentoNc = new FiscalDocument(
            tipoDocumento: 'nota_credito',
            serie: 'NC-ILU',
            numero: '1',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: $this->documento()->emisor,
            receptor: FiscalParty::consumidorFinal(),
            items: $this->documento()->items,
            pagos: $this->documento()->pagos,
            referencia: new \App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentReference(
                cufe: 'FE0120000...original',
                fecha: new DateTimeImmutable('2026-09-16T10:40:00-05:00'),
                razon: 'Devolución parcial',
            ),
        );

        $this->adapter()->certificarNotaCredito($documentoNc);

        Http::assertSent(fn ($request) => ($request->data()['referencia']['cufe'] ?? null) === 'FE0120000...original');
    }

    public function test_validar_receptor_mapea_respuesta_valida(): void
    {
        Http::fake([
            'fedigifact.test/v1/validar-ruc*' => Http::response([
                'ok' => true, 'valido' => true, 'nombre' => 'DIGIFACT SERVICIOS, S.A.', 'dv' => '32', 'tipoRuc' => '2',
            ], 200),
        ]);

        $resultado = $this->adapter()->validarReceptor('155704849-2-2021');

        $this->assertTrue($resultado->valido);
        $this->assertSame('DIGIFACT SERVICIOS, S.A.', $resultado->nombre);
        $this->assertSame('32', $resultado->dv);
    }

    public function test_consultar_estado_llama_endpoint_correcto(): void
    {
        Http::fake([
            'fedigifact.test/v1/documentos/ILU/1001' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...'], 200),
        ]);

        $resultado = $this->adapter()->consultarEstado('ILU', '1001');

        $this->assertTrue($resultado->certificado());
    }

    // ── SCRUM-1019 ────────────────────────────────────────────────────────────────────────────

    public function test_200_documento_ya_certificado_previamente_es_exito_con_el_cufe_original(): void
    {
        Http::fake(['fedigifact.test/v1/facturas' => Http::response([
            'ok' => true, 'mensaje' => 'Documento ya certificado previamente', 'cufe' => 'FE0120000...original',
            'qr' => 'https://qr.test/original', 'xmlFirmado' => base64_encode('<xml/>'),
        ], 200)]);

        $r = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('certificado', $r->estado);
        $this->assertSame('FE0120000...original', $r->cufe);
        $this->assertSame('https://qr.test/original', $r->qr);
        $this->assertSame(base64_encode('<xml/>'), $r->xmlFirmadoBase64);
    }

    public function test_200_ya_certificado_sin_cufe_no_se_da_por_certificado(): void
    {
        Http::fake(['fedigifact.test/v1/facturas' => Http::response(['ok' => true, 'mensaje' => 'Documento ya certificado previamente'], 200)]);

        $this->assertSame('error_configuracion', $this->adapter()->certificarFactura($this->documento())->estado);
    }

    public function test_9022_sigue_sin_ser_un_exito(): void
    {
        Http::fake(['fedigifact.test/v1/facturas' => Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9022 - La referencia INTERNA ya fue enviada a certificar']], 422)]);

        $r = $this->adapter()->certificarFactura($this->documento());

        $this->assertSame('rechazado', $r->estado);
        $this->assertNull($r->cufe);
    }

    public function test_validar_receptor_con_dv_vacio_no_rompe_y_devuelve_dv_null(): void
    {
        Http::fake(['fedigifact.test/v1/validar-ruc*' => Http::response(['ok' => true, 'valido' => true, 'nombre' => 'ACME', 'dv' => '', 'tipoRuc' => '2'], 200)]);

        $r = $this->adapter()->validarReceptor('155704849-2-2021');

        $this->assertTrue($r->valido);
        $this->assertNull($r->dv);
    }

    public function test_el_payload_de_consumidor_final_no_lleva_ruc_ni_dv_y_un_dv_vacio_tampoco_se_envia(): void
    {
        Http::fake(['fedigifact.test/v1/facturas' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...'], 200)]);
        $cf = new FiscalParty(tipo: '02', ruc: '', dv: '', nombre: 'CONSUMIDOR FINAL');
        $doc = $this->documento();
        $this->adapter()->certificarFactura(new FiscalDocument('factura', 'ILU', '1002', $doc->fechaEmision, $doc->emisor, $cf, $doc->items, $doc->pagos));

        Http::assertSent(function ($request): bool {
            $receptor = $request->data()['receptor'];

            return $receptor['tipo'] === '02' && ! array_key_exists('ruc', $receptor) && ! array_key_exists('dv', $receptor);
        });

        $contribuyente = new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '', nombre: 'ACME');
        $this->adapter()->certificarFactura(new FiscalDocument('factura', 'ILU', '1003', $doc->fechaEmision, $doc->emisor, $contribuyente, $doc->items, $doc->pagos));
        Http::assertSent(fn ($request): bool => ($request->data()['numero'] ?? null) === '1003' && ! array_key_exists('dv', $request->data()['receptor']));
    }
}
