<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-756 — pipeline completo del comando `legacy:sync-proveedores`, con
 * `ProveedorExtractorContract` fakeado (sin SQL Server real, ver `FakeProveedorExtractor`). Sin
 * Matcher ni Loader-B — el pipeline más simple de los 3 patrones existentes.
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test
 * deja el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de
 * cualquier assert que dependa de `Model::getTable()` (mismo patrón que
 * `LegacySyncProductosCommandTest`).
 */
final class LegacySyncProveedoresCommandTest extends FeatureTestCase
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
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('atlantic_legacy.proveedores', ['icg_codproveedor' => 55]);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_y_registra_sync_run(): void
    {
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('atlantic_legacy.proveedores', ['icg_codproveedor' => 55]);

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('proveedores', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);
    }

    /**
     * Idempotencia real — correr el comando completo DOS VECES seguidas contra el mismo dataset
     * fake nunca duplica filas en `legacy.proveedores`, aunque cada corrida real sí queda
     * registrada como su propia fila en `legacy.sync_runs`.
     */
    public function test_re_correr_la_corrida_real_dos_veces_es_idempotente_y_no_duplica(): void
    {
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Proveedor::query()->where('icg_codproveedor', 55)->count());
        $this->assertSame(2, SyncRun::query()->count()); // 2 corridas reales, cada una registrada
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            ProveedorExtractorContract::class,
            new FakeProveedorExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            ProveedorExtractorContract::class,
            new FakeProveedorExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — mismo patrón que `LegacySyncProductosCommandTest`/`LegacySyncClientesCommandTest`
     * (hallazgo Pre-QA SCRUM-750): una excepción durante el staging (acá forzada con un `nombre`
     * de 300 chars contra la columna `varchar(255)` de `legacy.proveedores`) nunca debe escapar
     * sin capturar — el segundo `try/catch` de `LegacySyncProveedoresCommand::runPipeline()`
     * (alrededor de `stage()`) es lo que este test protege.
     */
    public function test_falla_de_staging_registra_sync_run_failed_y_no_deja_stack_trace_crudo(): void
    {
        $extraction = new ExtractedProveedores([
            new ProveedorRow(9001, str_repeat('X', 300), null, null, null, null, null),
        ]);
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor($extraction));

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
        $this->assertSame(0, Proveedor::query()->count());
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-proveedores', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function sampleExtraction(): ExtractedProveedores
    {
        return new ExtractedProveedores([
            new ProveedorRow(55, 'Iluminación Panamá S.A.', 'Ilumipan', '1234567-8-901234', 'Calle 50, Ciudad de Panamá', '507-1234567', 'ventas@ilumipan.com'),
        ]);
    }
}
