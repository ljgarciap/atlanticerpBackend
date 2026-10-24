<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentReference;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tests puros de NucJsonMapper (sin DB, sin red) — mismo patrón que
 * tests/Unit/Legacy/FacturaExtractorTest. Cubre el mapeo sección por sección, no el request HTTP
 * en sí (eso lo prueba DigifactPacAdapterTest con Http::fake).
 */
final class NucJsonMapperTest extends TestCase
{
    private function mapper(): NucJsonMapper
    {
        return new NucJsonMapper(branchCode: 'BR001');
    }

    public function test_mapea_emisor_juridico(): void
    {
        $emisor = new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILLUMINATION S.A.');

        $mapped = $this->mapper()->mapEmisor($emisor);

        $this->assertSame('155704849-2-2021', $mapped['TaxID']);
        $this->assertSame(2, $mapped['TaxIDType']);
        $this->assertSame('BR001', $mapped['BranchInfo']['Code']);
    }

    public function test_mapea_receptor_consumidor_final_como_cf(): void
    {
        $mapped = $this->mapper()->mapReceptor(FiscalParty::consumidorFinal());

        $this->assertSame('CF', $mapped['TaxID']);
        $this->assertSame('02', $mapped['TaxIDAdditionalInfo']['Info']['Value']['TipoReceptor']);
    }

    public function test_mapea_receptor_extranjero(): void
    {
        $receptor = new FiscalParty(tipo: '04', ruc: null, dv: null, nombre: 'Foreign Corp');

        $mapped = $this->mapper()->mapReceptor($receptor);

        $this->assertSame('EXTRANJERO', $mapped['TaxID']);
        $this->assertSame('04', $mapped['TaxIDAdditionalInfo']['Info']['Value']['TipoReceptor']);
    }

    public function test_mapea_receptor_contribuyente_usa_su_ruc(): void
    {
        $receptor = new FiscalParty(tipo: '01', ruc: '8-123-456', dv: '10', nombre: 'Cliente Jurídico');

        $mapped = $this->mapper()->mapReceptor($receptor);

        $this->assertSame('8-123-456', $mapped['TaxID']);
    }

    public function test_mapea_items_con_total_neto_de_descuento(): void
    {
        $items = [
            new FiscalDocumentItem(codigo: 'P1', descripcion: 'Lámpara LED', cantidad: 2, precioUnitario: 18.50, tasaItbmsCode: '01', descuento: 1.00),
        ];

        $mapped = $this->mapper()->mapItems($items);

        $this->assertSame('01', $mapped[0]['Taxes']['Code']);
        $this->assertSame(36.00, $mapped[0]['Totals']['TotalItem']); // (2*18.50) - 1.00
    }

    public function test_mapea_totales_suma_todas_las_lineas(): void
    {
        $items = [
            new FiscalDocumentItem(codigo: 'P1', descripcion: 'A', cantidad: 1, precioUnitario: 10.00, tasaItbmsCode: '01'),
            new FiscalDocumentItem(codigo: 'P2', descripcion: 'B', cantidad: 2, precioUnitario: 5.00, tasaItbmsCode: '00'),
        ];

        $totals = $this->mapper()->mapTotals($items);

        $this->assertSame(2, $totals['QtyItems']);
        $this->assertSame(20.00, $totals['GrandTotal']['InvoiceTotal']);
    }

    public function test_mapea_forma_de_pago_conocida(): void
    {
        $this->assertSame('02', $this->mapper()->mapMetodoPago('efectivo'));
        $this->assertSame('03', $this->mapper()->mapMetodoPago('tarjeta_credito'));
    }

    public function test_forma_de_pago_desconocida_cae_a_codigo_otro(): void
    {
        $this->assertSame('99', $this->mapper()->mapMetodoPago('criptomoneda'));
    }

    public function test_mapeo_de_forma_de_pago_no_coincide_con_msl_a_proposito(): void
    {
        // Confirmado en el comparativo previo: "01" es efectivo en MSL y crédito en el NUC —
        // este test documenta la divergencia, no un bug.
        $this->assertSame('01', $this->mapper()->mapMetodoPago('credito'));
        $this->assertNotSame('efectivo', 'credito'); // los vocabularios internos nunca se mezclan
    }

    public function test_mapea_referencia_de_nota_de_credito(): void
    {
        $referencia = new FiscalDocumentReference(
            cufe: 'FE0120000155704849-2-2021-...',
            fecha: new DateTimeImmutable('2026-09-01'),
            razon: 'Devolución parcial',
        );

        $mapped = $this->mapper()->mapReferencia($referencia);

        $this->assertSame('FE0120000155704849-2-2021-...', $mapped['H04']['H041']['Info']['CUFERef']);
    }

    public function test_referencia_nula_para_factura_normal(): void
    {
        $this->assertNull($this->mapper()->mapReferencia(null));
    }

    public function test_map_completo_arma_las_secciones_esperadas(): void
    {
        $documento = new FiscalDocument(
            tipoDocumento: 'factura',
            serie: 'ILU',
            numero: '1001',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILLUMINATION S.A.'),
            receptor: FiscalParty::consumidorFinal(),
            items: [new FiscalDocumentItem(codigo: 'P1', descripcion: 'Lámpara LED', cantidad: 1, precioUnitario: 18.50, tasaItbmsCode: '01')],
            pagos: [new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 19.80)],
        );

        $body = $this->mapper()->map($documento, ambiente: 'pruebas');

        $this->assertSame('01', $body['Header']['DocType']);
        $this->assertSame(2, $body['Header']['AdditionalIssueType']); // pruebas = 2
        $this->assertArrayNotHasKey('AdditionalDocumentInfo', $body); // sin referencia = sin bloque
    }

    public function test_map_completo_incluye_referencia_para_nota_de_credito(): void
    {
        $documento = new FiscalDocument(
            tipoDocumento: 'nota_credito',
            serie: 'NC-ILU',
            numero: '1',
            fechaEmision: new DateTimeImmutable('2026-09-17T10:00:00-05:00'),
            emisor: new FiscalParty(tipo: '01', ruc: '155704849-2-2021', dv: '32', nombre: 'ILLUMINATION S.A.'),
            receptor: FiscalParty::consumidorFinal(),
            items: [new FiscalDocumentItem(codigo: 'P1', descripcion: 'Devolución', cantidad: 1, precioUnitario: 18.50, tasaItbmsCode: '01')],
            pagos: [new FiscalPayment(metodoPagoInterno: 'efectivo', monto: 19.80)],
            referencia: new FiscalDocumentReference('FE01...', new DateTimeImmutable('2026-09-01'), 'Devolución parcial'),
        );

        $body = $this->mapper()->map($documento, ambiente: 'produccion');

        $this->assertSame('04', $body['Header']['DocType']);
        $this->assertSame(1, $body['Header']['AdditionalIssueType']); // producción = 1
        $this->assertArrayHasKey('AdditionalDocumentInfo', $body);
    }
}
