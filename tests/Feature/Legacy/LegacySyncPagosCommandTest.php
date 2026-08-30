<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPagos;
use App\Modules\Legacy\Data\PagoRow;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-761 — pipeline completo del comando `legacy:sync-pagos`, con `PagoExtractorContract`
 * fakeado (sin SQL Server real, ver `FakePagoExtractor`). Sin Matcher ni Loader-B — mismo patrón
 * que `LegacySyncProveedoresCommandTest`.
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test deja
 * el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de cualquier
 * assert que dependa de `Model::getTable()`.
 */
final class LegacySyncPagosCommandTest extends FeatureTestCase
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

    public function test_dry_run_stagea_pero_no_registra_sync_run(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 9001]);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_y_registra_sync_run(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('atlantic_legacy.pagos', ['icg_numero' => 9001]);

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('pagos', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);
    }

    public function test_re_correr_la_corrida_real_dos_veces_es_idempotente_y_no_duplica(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Pago::query()->where('icg_numero', 9001)->count());
        $this->assertSame(2, SyncRun::query()->count());
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            PagoExtractorContract::class,
            new FakePagoExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            PagoExtractorContract::class,
            new FakePagoExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — mismo patrón que `LegacySyncProveedoresCommandTest` (hallazgo Pre-QA SCRUM-750):
     * una excepción durante el staging (acá forzada con un `icg_origen` de 300 chars contra la
     * columna `varchar(10)` de `legacy.pagos`) nunca debe escapar sin capturar.
     */
    public function test_falla_de_staging_registra_sync_run_failed_y_no_deja_stack_trace_crudo(): void
    {
        $extraction = new ExtractedPagos([self::pagoRow(icgOrigen: str_repeat('X', 300))]);
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor($extraction));

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
        $this->assertSame(0, Pago::query()->count());
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-pagos', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function pagoRow(string $icgOrigen = 'V'): PagoRow
    {
        return new PagoRow(
            icgOrigen: $icgOrigen,
            icgTipodocumento: 'F',
            icgSerie: 'A',
            icgNumero: 9001,
            icgN: 'B',
            icgPosicion: 1,
            fechaDocumento: null,
            fechaVencimiento: null,
            importe: 1500.50,
            icgCodformapago: 3,
            estado: 'P',
            fechaSaldado: null,
        );
    }

    private static function sampleExtraction(): ExtractedPagos
    {
        return new ExtractedPagos([self::pagoRow()]);
    }
}
