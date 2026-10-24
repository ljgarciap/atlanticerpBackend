<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentReference;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\MslJsonMapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-943 — extraído de `MslPacAdapter` para reusar el mismo shape exacto en la previsualización
 * de modo `apagado` (`FiscalDocumentPreviewService`). Cubre lo mismo que ya cubría
 * `MslPacAdapterTest` para el payload (ahora vía este mapper directo, sin HTTP de por medio).
 */
final class MslJsonMapperTest extends TestCase
{
    private function documento(): FiscalDocument
    {
        return new FiscalDocument(
            tipoDocumento: 'factura',
            serie: 'ILU',
            numero: '1001',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILUMINATIONS S.A.'),
            receptor: FiscalParty::consumidorFinal(),
            items: [new FiscalDocumentItem(codigo: 'P1', descripcion: 'Lámpara LED', cantidad: 2, precioUnitario: 18.50, tasaItbmsCode: '01')],
            pagos: [new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 39.59)],
        );
    }

    public function test_mapea_el_shape_completo_para_una_factura(): void
    {
        $payload = (new MslJsonMapper())->map($this->documento(), 'pruebas');

        $this->assertSame('pruebas', $payload['ambiente']);
        $this->assertSame('ILU', $payload['serie']);
        $this->assertSame('1001', $payload['numero']);
        $this->assertSame('02', $payload['receptor']['tipo']);
        $this->assertSame('P1', $payload['items'][0]['codigo']);
        $this->assertSame('02', $payload['pagos'][0]['tipo']); // efectivo -> 02 (catálogo corregido)
        $this->assertArrayNotHasKey('referencia', $payload);
    }

    public function test_quita_los_apostrofos_del_codigo_y_de_la_descripcion_de_los_items(): void
    {
        // SCRUM-1188 — F-0008 de producción: MSL rechazaba (3010) el código «AEREO'_SVHYATT»; sin apóstrofo certifica. Lo demás se conserva tal cual.
        $doc = new FiscalDocument(
            tipoDocumento: 'factura', serie: 'ILU', numero: '1002', fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILUMINATIONS S.A.'), receptor: FiscalParty::consumidorFinal(),
            items: [
                new FiscalDocumentItem(codigo: "AEREO'_SVHYATT", descripcion: "SALA D'ANGELO \u{2019}VIP\u{2018} IN&OUT 36˚35*61mm", cantidad: 1, precioUnitario: 75.0, tasaItbmsCode: '01'),
                new FiscalDocumentItem(codigo: 'GE-06040-9230', descripcion: 'CINTA LED', cantidad: 3, precioUnitario: 28.0, tasaItbmsCode: '01'),
            ],
            pagos: [new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 80.25)],
        );

        $payload = (new MslJsonMapper())->map($doc, 'produccion');

        $this->assertSame('AEREO_SVHYATT', $payload['items'][0]['codigo']);
        $this->assertSame('SALA DANGELO VIP IN&OUT 36˚35*61mm', $payload['items'][0]['descripcion']);
        $this->assertSame('GE-06040-9230', $payload['items'][1]['codigo']);
        $this->assertSame('CINTA LED', $payload['items'][1]['descripcion']);
    }

    public function test_incluye_referencia_para_nota_de_credito(): void
    {
        $documentoNc = new FiscalDocument(
            tipoDocumento: 'nota_credito',
            serie: 'NC-ILU',
            numero: '1',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: $this->documento()->emisor,
            receptor: FiscalParty::consumidorFinal(),
            items: $this->documento()->items,
            pagos: $this->documento()->pagos,
            referencia: new FiscalDocumentReference(
                cufe: 'FE0120000...original',
                fecha: new DateTimeImmutable('2026-09-16T10:40:00-05:00'),
                razon: 'Devolución parcial',
            ),
        );

        $payload = (new MslJsonMapper())->map($documentoNc, 'pruebas');

        $this->assertSame('FE0120000...original', $payload['referencia']['cufe']);
        $this->assertSame('Devolución parcial', $payload['referencia']['razon']);
    }
}
