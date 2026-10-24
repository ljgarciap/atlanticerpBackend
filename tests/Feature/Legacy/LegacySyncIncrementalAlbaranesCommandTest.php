<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Data\ExtractedAlbaranes;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-albaranes`: hash-compare con fold XOR de items + circuit
 * breaker + Matcher que solo corre sobre albaranes realmente cambiados. Gap de cobertura real
 * encontrado en esta sesión (2026-09-14): el fork que implementó este comando solo agregó tests
 * del staging loader, ningún test a nivel de comando — a diferencia de toda otra entidad de este
 * batch, donde precisamente ESTA capa (circuit breaker, origen scheduler) escondía bugs reales.
 */
final class LegacySyncIncrementalAlbaranesCommandTest extends FeatureTestCase
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
        Cliente::query()->create(['icg_codcliente' => 55, 'razon_social' => 'Cliente Test']);
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::baseline(1)));

        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('albaranes', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);

        $albaran = Albaran::query()->where('icg_numalbaran', 1)->firstOrFail();
        $this->assertFalse((bool) $albaran->orphan);
    }

    public function test_re_correr_sin_cambios_no_reprocesa_ni_remachea(): void
    {
        Cliente::query()->create(['icg_codcliente' => 55, 'razon_social' => 'Cliente Test']);
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::baseline(5)));

        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
        $this->assertSame(0, $ultima->excluidos);
    }

    /**
     * Baseline=5 (no 1) — con baseline=1, cambiar la única fila es 100% de cambio, siempre por
     * encima del umbral del 20% del circuit breaker (mismo bug de dimensionamiento de fixture ya
     * encontrado y corregido en Facturas/Pagos en esta sesión).
     */
    public function test_circuit_breaker_frena_un_cambio_anormal_y_no_matchea(): void
    {
        Cliente::query()->create(['icg_codcliente' => 55, 'razon_social' => 'Cliente Test']);
        $fake = new MutableFakeAlbaranExtractor(self::baseline(5));
        $this->app->instance(AlbaranExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Las 5 cabeceras cambian (100% > 20% de umbral).
        $fake->setResult(self::baseline(5, facturado: true));
        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
    }

    public function test_dry_run_nunca_evalua_el_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::baseline(1)));

        $this->artisan('legacy:sync-incremental-albaranes', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function baseline(int $count, bool $facturado = false): ExtractedAlbaranes
    {
        $albaranes = array_map(
            static fn (int $i): AlbaranRow => new AlbaranRow(
                icgNumserie: 'A', icgNumalbaran: $i, icgN: 'B', icgCodcliente: 55,
                fecha: null, totalneto: 500.0, facturado: $facturado, icgCodvendedor: null,
                icgNumseriefac: null, icgNumfac: null,
            ),
            range(1, $count),
        );

        return new ExtractedAlbaranes($albaranes, []);
    }
}
