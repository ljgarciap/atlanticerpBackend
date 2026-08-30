<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Data\ExtractedAlbaranes;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranItem;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-759 — pipeline completo del comando `legacy:sync-albaranes`, con
 * `AlbaranExtractorContract` fakeado (sin SQL Server real, ver `FakeAlbaranExtractor`). Mismo
 * patrón de test que `LegacySyncPedidosCommandTest` (SCRUM-758).
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test deja
 * el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de cualquier
 * assert que dependa de `Model::getTable()`.
 */
final class LegacySyncAlbaranesCommandTest extends FeatureTestCase
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
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        // El matching SÍ corre y escribe dentro de legacy.* incluso en dry-run (mismo criterio
        // que LegacySyncPedidosCommand).
        $this->assertSame($cliente->id, $albaran->legacy_cliente_id);
        $this->assertFalse($albaran->orphan);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_matchea_y_registra_sync_run(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('albaranes', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);

        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 4321)->count());
    }

    /**
     * Idempotencia real, no solo declarada — mismo criterio pedido por el runbook §12.2 paso 3.
     */
    public function test_re_correr_el_comando_dos_veces_no_duplica_albaranes_ni_items(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Albaran::query()->where('icg_numalbaran', 5001)->count());
        $this->assertSame(1, AlbaranItem::query()->count());
        $this->assertSame(2, SyncRun::query()->count()); // 2 corridas reales, cada una registrada

        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $this->assertFalse($albaran->orphan); // el segundo run no lo revierte
    }

    public function test_albaran_sin_match_se_reporta_como_excluido_y_no_bloquea_el_resto(): void
    {
        Cliente::query()->create(['icg_codcliente' => 9999999, 'razon_social' => 'Otro Cliente']); // no matchea
        $extraction = new ExtractedAlbaranes(
            albaranes: [
                self::albaranRow(icgNumalbaran: 5001, icgCodcliente: 555), // no matchea
                self::albaranRow(icgNumalbaran: 5002, icgCodcliente: 9999999), // matchea
            ],
            items: [],
        );
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor($extraction));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);

        $orphanAlbaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $this->assertTrue($orphanAlbaran->orphan);
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            AlbaranExtractorContract::class,
            new FakeAlbaranExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            AlbaranExtractorContract::class,
            new FakeAlbaranExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — mismo patrón que `LegacySyncPedidosCommandTest::
     * test_falla_de_staging_o_matching_registra_sync_run_failed`: una excepción durante
     * staging/matching (acá forzada con un `icg_numserie` de 300 chars contra la columna
     * `varchar(10)` de `legacy.albaranes`) tiene que quedar registrada en `legacy.sync_runs`,
     * nunca escapar sin rastro.
     */
    public function test_falla_de_staging_o_matching_registra_sync_run_failed(): void
    {
        $extraction = new ExtractedAlbaranes(
            albaranes: [self::albaranRow(icgNumserie: str_repeat('X', 300))],
            items: [],
        );
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor($extraction));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(AlbaranExtractorContract::class, new FakeAlbaranExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function albaranRow(
        int $icgNumalbaran = 5001,
        ?int $icgCodcliente = 555,
        string $icgNumserie = 'A',
    ): AlbaranRow {
        return new AlbaranRow(
            icgNumserie: $icgNumserie,
            icgNumalbaran: $icgNumalbaran,
            icgN: 'B',
            icgCodcliente: $icgCodcliente,
            fecha: null,
            totalneto: 1500.50,
            facturado: false,
            icgCodvendedor: 77,
            icgNumseriefac: null,
            icgNumfac: null,
        );
    }

    private static function itemRow(): AlbaranItemRow
    {
        return new AlbaranItemRow(
            icgNumserie: 'A', icgNumalbaran: 5001, icgN: 'B',
            icgCodarticulo: 4321, referencia: 'REF-001', descripcion: 'Lámpara LED',
            cantidad: 3.0, precio: 100.0, total: 300.0,
        );
    }

    private static function sampleExtraction(): ExtractedAlbaranes
    {
        return new ExtractedAlbaranes(
            albaranes: [self::albaranRow()],
            items: [self::itemRow()],
        );
    }
}
