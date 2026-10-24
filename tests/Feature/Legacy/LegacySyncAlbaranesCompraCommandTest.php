<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-840 — pipeline completo del comando `legacy:sync-albaranes-compra`, con
 * `AlbaranCompraExtractorContract` fakeado. Mismo patrón de test que
 * `LegacySyncAlbaranesCommandTest` (SCRUM-759), vinculando a Proveedor en vez de Cliente.
 */
final class LegacySyncAlbaranesCompraCommandTest extends FeatureTestCase
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
        $proveedor = Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Legal SA']);
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $albaran = AlbaranCompra::query()->where('icg_numalbaran', 3001)->firstOrFail();
        $this->assertSame($proveedor->id, $albaran->legacy_proveedor_id);
        $this->assertFalse($albaran->orphan);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_matchea_y_registra_sync_run(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Legal SA']);
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('albaranes_compra', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);

        $this->assertSame(1, AlbaranCompraItem::query()->where('icg_codarticulo', 1234)->count());
    }

    public function test_re_correr_el_comando_dos_veces_no_duplica_albaranes_ni_items(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 42, 'nombre' => 'Proveedor Legal SA']);
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, AlbaranCompra::query()->where('icg_numalbaran', 3001)->count());
        $this->assertSame(1, AlbaranCompraItem::query()->count());
        $this->assertSame(2, SyncRun::query()->count());

        $albaran = AlbaranCompra::query()->where('icg_numalbaran', 3001)->firstOrFail();
        $this->assertFalse($albaran->orphan);
    }

    public function test_albaran_sin_match_se_reporta_como_excluido_y_no_bloquea_el_resto(): void
    {
        Proveedor::query()->create(['icg_codproveedor' => 9999999, 'nombre' => 'Otro Proveedor']); // no matchea
        $extraction = new ExtractedAlbaranesCompra(
            albaranes: [
                self::albaranRow(icgNumalbaran: 3001, icgCodproveedor: 42), // no matchea
                self::albaranRow(icgNumalbaran: 3002, icgCodproveedor: 9999999), // matchea
            ],
            items: [],
        );
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor($extraction));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);

        $orphanAlbaran = AlbaranCompra::query()->where('icg_numalbaran', 3001)->firstOrFail();
        $this->assertTrue($orphanAlbaran->orphan);
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            AlbaranCompraExtractorContract::class,
            new FakeAlbaranCompraExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            AlbaranCompraExtractorContract::class,
            new FakeAlbaranCompraExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_falla_de_staging_o_matching_registra_sync_run_failed(): void
    {
        $extraction = new ExtractedAlbaranesCompra(
            albaranes: [self::albaranRow(icgNumserie: str_repeat('X', 300))],
            items: [],
        );
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor($extraction));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(AlbaranCompraExtractorContract::class, new FakeAlbaranCompraExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-albaranes-compra', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function albaranRow(
        int $icgNumalbaran = 3001,
        ?int $icgCodproveedor = 42,
        string $icgNumserie = 'A',
    ): AlbaranCompraRow {
        return new AlbaranCompraRow(
            icgNumserie: $icgNumserie,
            icgNumalbaran: $icgNumalbaran,
            icgN: 'B',
            icgCodproveedor: $icgCodproveedor,
            fecha: null,
            totalneto: 850.75,
            facturado: false,
            icgNumseriefac: null,
            icgNumfac: null,
        );
    }

    private static function itemRow(): AlbaranCompraItemRow
    {
        return new AlbaranCompraItemRow(
            icgNumserie: 'A', icgNumalbaran: 3001, icgN: 'B',
            icgCodarticulo: 1234, referencia: 'REF-COMPRA-01', descripcion: 'Perfil de aluminio',
            cantidad: 10.0, precio: 25.0, total: 250.0,
        );
    }

    private static function sampleExtraction(): ExtractedAlbaranesCompra
    {
        return new ExtractedAlbaranesCompra(
            albaranes: [self::albaranRow()],
            items: [self::itemRow()],
        );
    }
}
