<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\CommissionTier;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\VentasDiseno\Services\SalesTotalsService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14g (docs/specs/fase14g-alinear-tramos-comision.md) — Admin & Contab y Ventas aplican la misma escala con los valores sembrados: el mismo
 * total da el mismo porcentaje en ambos, incluidos los cortes. La migración solo toca la tabla si sigue en el valor sembrado.
 */
final class CommissionScalesAlignmentTest extends FeatureTestCase
{
    private const ARCHIVO = '2026_11_07_100000_align_internal_commission_tiers_with_sales.php';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, array{float, float}> */
    public static function totales(): array
    {
        return [
            '19.999,99' => [19999.99, 1.5], '20.000' => [20000.0, 2.0], '20.000,50' => [20000.5, 2.0], '34.999,99' => [34999.99, 2.0],
            '35.000' => [35000.0, 3.0], '59.999,99' => [59999.99, 3.0], '60.000' => [60000.0, 4.0], '79.999,99' => [79999.99, 4.0],
            '80.000' => [80000.0, 4.5], '80.500' => [80500.0, 4.5], '119.999,99' => [119999.99, 4.5], '120.000' => [120000.0, 5.0],
        ];
    }

    #[DataProvider('totales')]
    public function test_el_mismo_total_da_el_mismo_porcentaje_en_ambos_modulos(float $total, float $esperado): void
    {
        $this->assertSame($esperado, app(CommissionOrderService::class)->percentFor($total), 'Admin & Contab');
        $this->assertSame($esperado, app(SalesTotalsService::class)->percentFor($total), 'Ventas');
    }

    private function migracion(): object
    {
        return require base_path('database/migrations/tenant/'.self::ARCHIVO);
    }

    private function restaurarValoresSembradosOriginales(): void
    {
        $this->migracion()->down();
    }

    public function test_la_migracion_iguala_la_tabla_sembrada_y_down_la_restaura(): void
    {
        $this->restaurarValoresSembradosOriginales();
        $this->assertSame([0.0, 20001.0, 35001.0, 60001.0, 81001.0, 120001.0], CommissionTier::query()->orderBy('orden')->pluck('monto_minimo')->map(floatval(...))->all());

        $this->migracion()->up();
        $this->assertSame([0.0, 20000.0, 35000.0, 60000.0, 80000.0, 120000.0], CommissionTier::query()->orderBy('orden')->pluck('monto_minimo')->map(floatval(...))->all());
    }

    public function test_una_tabla_personalizada_no_se_toca(): void
    {
        $this->restaurarValoresSembradosOriginales();
        CommissionTier::query()->where('orden', 2)->update(['porcentaje' => 2.5]); // la empresa cambió un porcentaje

        $this->migracion()->up();

        $this->assertSame([0.0, 20001.0, 35001.0, 60001.0, 81001.0, 120001.0], CommissionTier::query()->orderBy('orden')->pluck('monto_minimo')->map(floatval(...))->all());
        $this->assertSame(2.5, (float) CommissionTier::query()->where('orden', 2)->value('porcentaje'));
    }

    public function test_el_endpoint_avisa_cuando_las_escalas_difieren(): void
    {
        $token = $this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab');
        $h = $this->authHeaders($token);

        $this->withHeaders($h)->getJson('/api/admin-contab/commissions/internal/tiers/vs-sales')->assertOk()->assertJsonPath('coinciden', true);

        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        DB::table('atlantic_admin_contab.commission_tiers')->where('orden', 5)->update(['monto_minimo' => 81001]);

        $r = $this->withHeaders($h)->getJson('/api/admin-contab/commissions/internal/tiers/vs-sales')->assertOk()->assertJsonPath('coinciden', false);
        $this->assertSame(80000.0, (float) $r->json('ventas.4.monto_minimo'));
        $this->assertSame(81001.0, (float) $r->json('admin.4.monto_minimo'));
    }
}
