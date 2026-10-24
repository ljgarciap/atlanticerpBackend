<?php

declare(strict_types=1);

namespace Tests\Unit\AdminCont;

use App\Modules\AdminCont\FacturacionElectronica\Exceptions\UnmappableItbmsRateException;
use App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsCodeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItbmsCodeMapperTest extends TestCase
{
    /** @return array<string, array{float, string}> */
    public static function tasasValidas(): array
    {
        return [
            'exento' => [0.0, '00'],
            'general 7%' => [7.0, '01'],
            '10%' => [10.0, '02'],
            '15%' => [15.0, '03'],
        ];
    }

    #[DataProvider('tasasValidas')]
    public function test_mapea_tasas_estandar_al_codigo_dgi(float $porcentaje, string $esperado): void
    {
        $this->assertSame($esperado, (new ItbmsCodeMapper())->map($porcentaje));
    }

    public function test_tasa_desconocida_lanza_excepcion_en_vez_de_default_silencioso(): void
    {
        $this->expectException(UnmappableItbmsRateException::class);

        (new ItbmsCodeMapper())->map(99.9);
    }

    public function test_retencion_3_5_no_se_certifica_hasta_confirmar_criterio_fiscal(): void
    {
        $this->expectException(UnmappableItbmsRateException::class);
        $this->expectExceptionMessage('Retención de impuesto');

        (new ItbmsCodeMapper())->map(3.5);
    }
}
