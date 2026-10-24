<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentItemsBuilder;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentSerieResolver;
use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsCodeMapper;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-987 — test puro (sin DB, sin red) de `FiscalDocumentBuilder::numeroFiscal()`, con
 * expectativas hardcodeadas (no reimplementa la regex del propio método, a diferencia de las
 * aserciones que ya existían en FiscalDocumentBuilderNotaCreditoTest/FiscalCertificationServiceTest
 * — esas dos siguen sirviendo como test de integración del wiring, esta es la única que de verdad
 * puede atrapar una regresión en la transformación en sí).
 */
final class FiscalDocumentBuilderNumeroFiscalTest extends TestCase
{
    private function builder(): FiscalDocumentBuilder
    {
        $itbmsCodeMapper = new ItbmsCodeMapper();

        return new FiscalDocumentBuilder(
            itemsBuilder: new FiscalDocumentItemsBuilder($itbmsCodeMapper, new \App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsLineBalancer()),
            serieResolver: new FiscalDocumentSerieResolver(),
            itbmsCodeMapper: $itbmsCodeMapper,
        );
    }

    public function test_extrae_solo_digitos_de_un_folio_de_factura_sin_recortar_ceros(): void
    {
        // A propósito conserva el padding ("0013", no "13") — solo se confirmó que letras/guión
        // rompen la validación de la DGI, nunca que un cero a la izquierda también lo haría.
        $this->assertSame('0013', $this->builder()->numeroFiscal('F-0013'));
    }

    public function test_extrae_solo_digitos_de_un_folio_de_nota_de_credito(): void
    {
        $this->assertSame('0001', $this->builder()->numeroFiscal('NC-0001'));
    }

    public function test_no_deja_el_numero_vacio_si_el_folio_no_tiene_digitos(): void
    {
        $this->assertSame('0', $this->builder()->numeroFiscal('F-'));
    }

    public function test_folio_ya_puramente_numerico_no_se_altera(): void
    {
        $this->assertSame('9001', $this->builder()->numeroFiscal('9001'));
    }
}
