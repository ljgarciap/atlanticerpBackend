<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-757 — pipeline completo del comando `legacy:sync-cotizaciones`, con
 * `CotizacionExtractorContract` fakeado (sin SQL Server real, ver `FakeCotizacionExtractor`).
 * Mismo patrón de test que `LegacySyncClientesCommandTest` (SCRUM-755).
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test deja
 * el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de cualquier
 * assert que dependa de `Model::getTable()`.
 */
final class LegacySyncCotizacionesCommandTest extends FeatureTestCase
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

    public function test_dry_run_stagea_y_matchea_pero_no_registra_sync_run(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $cotizacion = Cotizacion::query()->where('icg_numpresupuesto', 1001)->firstOrFail();
        // El matching SÍ corre y escribe dentro de legacy.* incluso en dry-run (mismo criterio
        // que LegacySyncClientesCommand).
        $this->assertSame($cliente->id, $cotizacion->legacy_cliente_id);
        $this->assertFalse($cotizacion->orphan);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_matchea_y_registra_sync_run(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('cotizaciones', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);

        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 4321)->count());
    }

    /**
     * Idempotencia real, no solo declarada — mismo criterio pedido por el runbook §12.2 paso 3.
     */
    public function test_re_correr_el_comando_dos_veces_no_duplica_cotizaciones_ni_items(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Cotizacion::query()->where('icg_numpresupuesto', 1001)->count());
        $this->assertSame(1, CotizacionItem::query()->count());
        $this->assertSame(2, SyncRun::query()->count()); // 2 corridas reales, cada una registrada

        $cotizacion = Cotizacion::query()->where('icg_numpresupuesto', 1001)->firstOrFail();
        $this->assertFalse($cotizacion->orphan); // el segundo run no lo revierte
    }

    public function test_cotizacion_sin_match_se_reporta_como_excluida_y_no_bloquea_el_resto(): void
    {
        Cliente::query()->create(['icg_codcliente' => 9999999, 'razon_social' => 'Otro Cliente']); // no matchea
        $extraction = new ExtractedCotizaciones(
            cotizaciones: [
                self::cotizacionRow(icgNumpresupuesto: 1001, icgCodcliente: 555), // no matchea
                self::cotizacionRow(icgNumpresupuesto: 1002, icgCodcliente: 9999999), // matchea
            ],
            items: [],
        );
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor($extraction));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);

        $orphanCotizacion = Cotizacion::query()->where('icg_numpresupuesto', 1001)->firstOrFail();
        $this->assertTrue($orphanCotizacion->orphan);
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            CotizacionExtractorContract::class,
            new FakeCotizacionExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            CotizacionExtractorContract::class,
            new FakeCotizacionExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — mismo patrón que `LegacySyncClientesCommandTest::
     * test_falla_de_staging_o_matching_registra_sync_run_failed`: una excepción durante
     * staging/matching (acá forzada con un `icg_numserie` de 300 chars contra la columna
     * `varchar(10)` de `legacy.cotizaciones`) tiene que quedar registrada en `legacy.sync_runs`,
     * nunca escapar sin rastro.
     */
    public function test_falla_de_staging_o_matching_registra_sync_run_failed(): void
    {
        $extraction = new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow(icgNumserie: str_repeat('X', 300))],
            items: [],
        );
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor($extraction));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(CotizacionExtractorContract::class, new FakeCotizacionExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-cotizaciones', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function cotizacionRow(
        int $icgNumpresupuesto = 1001,
        ?int $icgCodcliente = 555,
        string $icgNumserie = 'A',
    ): CotizacionRow {
        return new CotizacionRow(
            icgNumserie: $icgNumserie,
            icgNumpresupuesto: $icgNumpresupuesto,
            icgN: 1,
            icgVersion: 1,
            icgCodcliente: $icgCodcliente,
            fecha: null,
            totalNeto: 1500.50,
            totalBruto: 1650.75,
            icgCodvendedor: 77,
            idestado: 'P',
            supresupuesto: null,
        );
    }

    private static function itemRow(): CotizacionItemRow
    {
        return new CotizacionItemRow(
            icgNumserie: 'A', icgNumpresupuesto: 1001, icgN: 1, icgVersion: 1,
            icgCodarticulo: 4321, referencia: 'REF-001', descripcion: 'Lámpara LED',
            cantidad: 3.0, precio: 100.0, descuento: 10.0, total: 270.0, costo: 60.0,
        );
    }

    private static function sampleExtraction(): ExtractedCotizaciones
    {
        return new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow()],
            items: [self::itemRow()],
        );
    }
}
