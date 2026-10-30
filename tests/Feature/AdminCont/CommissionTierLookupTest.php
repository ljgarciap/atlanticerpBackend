<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Shared\Multitenancy\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14d (3.5) — el tramo de comisión se elige por su límite inferior inclusivo: con los tramos sembrados (0–20.000, 20.001–35.000…) no
 * queda ningún total sin tramo (antes 20.000,50 daba 0 %).
 */
final class CommissionTierLookupTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, array{float, float}> */
    public static function totales(): array
    {
        return [
            'cero' => [0.0, 0.0],
            'dentro del primero' => [10313.24, 1.5],
            'borde superior del primero' => [20000.0, 1.5],
            'hueco entre 20.000 y 20.001' => [20000.5, 1.5],
            'límite inferior inclusivo del segundo' => [20001.0, 2.0],
            'segundo' => [35000.0, 2.0],
            'tercero' => [35001.0, 3.0],
            'el último, sin techo' => [500000.0, 5.0],
        ];
    }

    #[DataProvider('totales')]
    public function test_cada_total_cae_en_un_tramo(float $total, float $esperado): void
    {
        $this->assertSame($esperado, app(CommissionOrderService::class)->percentFor($total));
    }
}
