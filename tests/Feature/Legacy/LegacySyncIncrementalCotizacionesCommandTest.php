<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class LegacySyncIncrementalCotizacionesCommandTest extends FeatureTestCase
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

    public function test_corrida_real_matchea_contra_clientes_y_registra_origen_scheduler(): void
    {
        Cliente::query()->create(['icg_codcliente' => 2001, 'razon_social' => 'Cliente X']);

        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(
            new ExtractedCotizaciones([new CotizacionRow('A', 1, 1, 1, 2001, null, 500.0, 550.0, 5, 'P', null)], []),
        ));

        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);
    }

    public function test_re_correr_sin_cambios_no_ejecuta_el_matcher(): void
    {
        Cliente::query()->create(['icg_codcliente' => 2001, 'razon_social' => 'Cliente X']);
        $extracted = new ExtractedCotizaciones([new CotizacionRow('A', 1, 1, 1, 2001, null, 500.0, 550.0, 5, 'P', null)], []);

        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor($extracted));
        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(0, $ultima->mapeados); // el Matcher ni corrió
    }

    public function test_circuit_breaker_frena_una_corrida_con_volumen_de_cambio_anormal(): void
    {
        $primera = new ExtractedCotizaciones([
            new CotizacionRow('A', 1, 1, 1, null, null, 100.0, null, null, null, null),
            new CotizacionRow('A', 2, 1, 1, null, null, 100.0, null, null, null, null),
            new CotizacionRow('A', 3, 1, 1, null, null, 100.0, null, null, null, null),
            new CotizacionRow('A', 4, 1, 1, null, null, 100.0, null, null, null, null),
        ], []);
        $fake = new MutableFakeCotizacionExtractor($primera);
        $this->app->instance(CotizacionExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $segunda = new ExtractedCotizaciones([
            new CotizacionRow('A', 1, 1, 1, null, null, 999.0, null, null, null, null),
            new CotizacionRow('A', 2, 1, 1, null, null, 999.0, null, null, null, null),
            new CotizacionRow('A', 3, 1, 1, null, null, 999.0, null, null, null, null),
            new CotizacionRow('A', 4, 1, 1, null, null, 100.0, null, null, null, null),
        ], []);
        $fake->setResult($segunda);
        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
    }

    public function test_dry_run_nunca_evalua_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(
            new ExtractedCotizaciones([new CotizacionRow('A', 1, 1, 1, null, null, 500.0, null, null, null, null)], []),
        ));

        $this->artisan('legacy:sync-incremental-cotizaciones', ['--tenant' => 'atlantic', '--dry-run' => true])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }
}
