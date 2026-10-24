<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-albaranes-compra`: mismo gap de cobertura corregido que
 * LegacySyncIncrementalAlbaranesCommandTest (el fork original solo testeó el staging loader).
 */
final class LegacySyncIncrementalAlbaranesCompraCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_primera_corrida_matchea_y_registra_origen_scheduler(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Test']);
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::baseline(1)));

        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('albaranes_compra', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);

        $albaran = AlbaranCompra::query()->where('icg_numalbaran', 1)->firstOrFail();
        $this->assertFalse((bool) $albaran->orphan);
    }

    public function test_re_correr_sin_cambios_no_reprocesa_ni_remachea(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Test']);
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::baseline(5)));

        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
        $this->assertSame(0, $ultima->excluidos);
    }

    /** Baseline=5 (no 1) — ver mismo criterio en LegacySyncIncrementalAlbaranesCommandTest. */
    public function test_circuit_breaker_frena_un_cambio_anormal_y_no_matchea(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Test']);
        $fake = new MutableFakeAlbaranCompraExtractor(self::baseline(5));
        $this->app->instance(AlbaranCompraExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $fake->setResult(self::baseline(5, facturado: true));
        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
    }

    public function test_dry_run_nunca_evalua_el_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::baseline(1)));

        $this->artisan('legacy:sync-incremental-albaranes-compra', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function baseline(int $count, bool $facturado = false): ExtractedAlbaranesCompra
    {
        $albaranes = array_map(
            static fn (int $i): AlbaranCompraRow => new AlbaranCompraRow(
                icgNumserie: 'C', icgNumalbaran: $i, icgN: 'B', icgCodproveedor: 42,
                fecha: null, totalneto: 500.0, facturado: $facturado,
                icgNumseriefac: null, icgNumfac: null,
            ),
            range(1, $count),
        );

        return new ExtractedAlbaranesCompra($albaranes, []);
    }
}
