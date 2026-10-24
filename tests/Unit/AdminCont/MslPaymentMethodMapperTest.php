<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-943 (2026-09-24) — códigos corregidos contra el catálogo real de
 * `https://fedigifact.appmsl.com/referencia` §4 (ver docblock de MslPaymentMethodMapper para el
 * detalle del bug encontrado: los códigos previos venían de los PDF de entrega del proveedor,
 * corridos respecto al catálogo en vivo).
 */
final class MslPaymentMethodMapperTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function metodosDocumentadosPorMsl(): array
    {
        return [
            'efectivo' => ['efectivo', '02'],
            'tarjeta' => ['tarjeta', '03'],
            'transferencia' => ['transferencia', '08'],
            'deposito' => ['deposito', '08'],
            'cheque' => ['cheque', '09'],
        ];
    }

    #[DataProvider('metodosDocumentadosPorMsl')]
    public function test_mapea_metodos_documentados_explicitamente(string $metodoInterno, string $codigoEsperado): void
    {
        $resultado = (new MslPaymentMethodMapper())->map($metodoInterno, 'etiqueta');

        $this->assertSame($codigoEsperado, $resultado['tipo']);
        $this->assertNull($resultado['descripcion']);
    }

    /** @return array<string, array{string}> */
    public static function metodosSinCodigoMslExplicito(): array
    {
        return [
            'yappy' => ['yappy'],
            'link_pago' => ['link_pago'],
            'retencion_impuestos' => ['retencion_impuestos'],
            'ajuste_cuenta' => ['ajuste_cuenta'],
        ];
    }

    #[DataProvider('metodosSinCodigoMslExplicito')]
    public function test_metodos_sin_codigo_documentado_caen_a_otro_con_descripcion(string $metodoInterno): void
    {
        $resultado = (new MslPaymentMethodMapper())->map($metodoInterno, 'Retención de impuestos');

        $this->assertSame('99', $resultado['tipo']);
        $this->assertSame('Retención de impuestos', $resultado['descripcion']);
    }

    public function test_metodo_interno_credito_mapea_a_tipo_01(): void
    {
        $resultado = (new MslPaymentMethodMapper())->map(MslPaymentMethodMapper::METODO_INTERNO_CREDITO, 'no debería usarse');

        $this->assertSame(MslPaymentMethodMapper::TIPO_CREDITO, $resultado['tipo']);
        $this->assertSame('01', $resultado['tipo']);
        $this->assertNull($resultado['descripcion']);
    }
}
