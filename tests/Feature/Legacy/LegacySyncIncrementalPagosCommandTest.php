<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPagos;
use App\Modules\Legacy\Data\PagoRow;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use DateTimeImmutable;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-pagos`: hash-compare paginado (incluye fecha_saldado, que
 * puede setearse mucho después del alta del pago) + circuit breaker. Sin Matcher (TESORERIA no
 * referencia cliente) — el hash-compare del staging es el ahorro completo para esta entidad.
 */
final class LegacySyncIncrementalPagosCommandTest extends FeatureTestCase
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

    public function test_primera_corrida_cuenta_todo_como_nuevo_y_registra_origen_scheduler(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('pagos', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, Pago::query()->count());
    }

    public function test_re_correr_sin_cambios_no_reprocesa(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        // Nada cambió: mapeados/cargados en 0 — no se cuenta lo "sin cambios" como trabajo hecho.
        $this->assertSame(0, $ultima->mapeados);
        $this->assertSame(0, $ultima->cargados);
        $this->assertSame(1, Pago::query()->count());
    }

    /**
     * Caso real que motivó incluir fecha_saldado en el hash: un pago se concilia (se salda) mucho
     * después de su fecha de alta, sin que cambien ORIGEN/TIPODOCUMENTO/SERIE/NUMERO/N/POSICION.
     */
    public function test_un_pago_que_se_salda_despues_se_detecta_como_cambio(): void
    {
        // 5 pagos de baseline (no 1) — con baseline=1, saldar la única fila es 100% de cambio,
        // siempre por encima del umbral del 20% del circuit breaker aunque el cambio sea normal.
        // Bug real de dimensionamiento del fixture encontrado en esta sesión (2026-09-14, mismo
        // hallazgo que el test equivalente de Facturas) — no es un bug del circuit breaker en sí.
        $baseline = array_map(
            static fn (int $i): PagoRow => new PagoRow('FAC', 'F', 'A', $i, '1', 1,
                new DateTimeImmutable('2026-01-01'), null, 100.0, 1, 'PENDIENTE', null),
            range(1, 5),
        );
        $fake = new MutableFakePagoExtractor(new ExtractedPagos($baseline));
        $this->app->instance(PagoExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $saldado = new ExtractedPagos([
            ...array_map(
                static fn (int $i): PagoRow => new PagoRow('FAC', 'F', 'A', $i, '1', 1,
                    new DateTimeImmutable('2026-01-01'), null, 100.0, 1, 'PENDIENTE', null),
                range(2, 5),
            ),
            new PagoRow('FAC', 'F', 'A', 1, '1', 1,
                new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'),
                100.0, 1, 'PAGADO', new DateTimeImmutable('2026-03-15')),
        ]);
        $fake->setResult($saldado);
        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(1, $ultima->mapeados);

        $pago = Pago::query()->where('icg_numero', 1)->firstOrFail();
        $this->assertNotNull($pago->fecha_saldado);
        $this->assertSame('PAGADO', $pago->estado);
    }

    public function test_circuit_breaker_marca_held_for_review_con_cambio_anormal(): void
    {
        $pagosIniciales = array_map(
            static fn (int $i): PagoRow => new PagoRow('FAC', 'F', 'A', $i, '1', 1, null, null, 100.0, 1, 'PENDIENTE', null),
            range(1, 4),
        );
        $fake = new MutableFakePagoExtractor(new ExtractedPagos($pagosIniciales));
        $this->app->instance(PagoExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // 3 de 4 (75% > 20%) cambian de estado.
        $pagosCambiados = [
            new PagoRow('FAC', 'F', 'A', 1, '1', 1, null, null, 100.0, 1, 'PAGADO', null),
            new PagoRow('FAC', 'F', 'A', 2, '1', 1, null, null, 100.0, 1, 'PAGADO', null),
            new PagoRow('FAC', 'F', 'A', 3, '1', 1, null, null, 100.0, 1, 'PAGADO', null),
            new PagoRow('FAC', 'F', 'A', 4, '1', 1, null, null, 100.0, 1, 'PENDIENTE', null),
        ];
        $fake->setResult(new ExtractedPagos($pagosCambiados));
        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);

        // El staging SÍ se actualizó — acá no hay Matcher que frenar, el circuit breaker es
        // puramente informativo/de alerta para esta entidad (ver docblock del comando).
        $pago1 = Pago::query()->where('icg_numero', 1)->firstOrFail();
        $this->assertSame('PAGADO', $pago1->estado);
    }

    public function test_dry_run_nunca_registra_corrida(): void
    {
        $this->app->instance(PagoExtractorContract::class, new FakePagoExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-pagos', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function sampleExtraction(): ExtractedPagos
    {
        return new ExtractedPagos([
            new PagoRow('FAC', 'F', 'A', 1, '1', 1, new DateTimeImmutable('2026-01-01'), null, 100.0, 1, 'PENDIENTE', null),
        ]);
    }
}
