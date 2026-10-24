<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class LegacySyncIncrementalProveedoresCommandTest extends FeatureTestCase
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

    public function test_corrida_real_registra_origen_scheduler(): void
    {
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('proveedores', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);
    }

    public function test_circuit_breaker_frena_una_corrida_con_volumen_de_cambio_anormal(): void
    {
        $primera = new ExtractedProveedores([
            new ProveedorRow(1, 'Proveedor 1', null, null, null, null, null),
            new ProveedorRow(2, 'Proveedor 2', null, null, null, null, null),
            new ProveedorRow(3, 'Proveedor 3', null, null, null, null, null),
            new ProveedorRow(4, 'Proveedor 4', null, null, null, null, null),
        ]);
        $fake = new MutableFakeProveedorExtractor($primera);
        $this->app->instance(ProveedorExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $segunda = new ExtractedProveedores([
            new ProveedorRow(1, 'Proveedor 1 (renombrado)', null, null, null, null, null),
            new ProveedorRow(2, 'Proveedor 2 (renombrado)', null, null, null, null, null),
            new ProveedorRow(3, 'Proveedor 3 (renombrado)', null, null, null, null, null),
            new ProveedorRow(4, 'Proveedor 4', null, null, null, null, null),
        ]);
        $fake->setResult($segunda);
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->cargados);
    }

    public function test_corrida_sin_cambios_reales_registra_cargados_cero(): void
    {
        // SCRUM-922 — regresión: antes del fix, una segunda corrida sin ningún cambio real
        // reportaba cargados=extraidos (100% del catálogo), en vez del delta real (0).
        $primera = new ExtractedProveedores([
            new ProveedorRow(501, 'Proveedor Uno', null, null, null, null, null),
            new ProveedorRow(502, 'Proveedor Dos', null, null, null, null, null),
        ]);
        $fake = new MutableFakeProveedorExtractor($primera);
        $this->app->instance(ProveedorExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Segunda corrida: exactamente la misma data, ningún proveedor cambió de verdad.
        $fake->setResult(new ExtractedProveedores([
            new ProveedorRow(501, 'Proveedor Uno', null, null, null, null, null),
            new ProveedorRow(502, 'Proveedor Dos', null, null, null, null, null),
        ]));
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(2, $ultima->extraidos);
        $this->assertSame(0, $ultima->mapeados);
        $this->assertSame(0, $ultima->cargados);
    }

    public function test_corrida_con_cambios_reales_registra_delta_no_el_total(): void
    {
        // 10 proveedores para que 1 cambio real quede bajo el umbral del circuit breaker (20%)
        // y no se confunda con ese mecanismo (distinto de test_circuit_breaker_frena_...).
        $filas = array_map(
            static fn (int $i): ProveedorRow => new ProveedorRow($i, "Proveedor {$i}", null, null, null, null, null),
            range(501, 510),
        );
        $fake = new MutableFakeProveedorExtractor(new ExtractedProveedores($filas));
        $this->app->instance(ProveedorExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Segunda corrida: solo 1 de los 10 proveedores cambió de verdad.
        $filas[0] = new ProveedorRow(501, 'Proveedor 501 (renombrado)', null, null, null, null, null);
        $fake->setResult(new ExtractedProveedores($filas));
        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(10, $ultima->extraidos);
        $this->assertSame(1, $ultima->mapeados);
        $this->assertSame(1, $ultima->cargados);
    }

    public function test_dry_run_nunca_registra_corrida(): void
    {
        $this->app->instance(ProveedorExtractorContract::class, new FakeProveedorExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-proveedores', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function sampleExtraction(): ExtractedProveedores
    {
        return new ExtractedProveedores([
            new ProveedorRow(501, 'Proveedor Uno', null, null, null, null, null),
        ]);
    }
}
