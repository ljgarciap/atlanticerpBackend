<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-facturas`: hash-compare (incluye campos fe_*) + circuit
 * breaker + Matcher que solo corre sobre facturas realmente cambiadas.
 */
final class LegacySyncIncrementalFacturasCommandTest extends FeatureTestCase
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
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('facturas', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);

        $factura = Factura::query()->where('icg_numserie', 'A')->firstOrFail();
        $this->assertFalse((bool) $factura->orphan);
    }

    public function test_re_correr_sin_cambios_no_reprocesa_ni_remachea(): void
    {
        Cliente::query()->create(['icg_codcliente' => 55, 'razon_social' => 'Cliente Test']);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $runs = SyncRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $runs->last()->status);
        // Sin cambios reales: el Matcher ni corre — mismo criterio ya corregido en Productos.
        $this->assertSame(0, $runs->last()->mapeados);
        $this->assertSame(0, $runs->last()->excluidos);
    }

    /**
     * El caso real que motivó incluir fe_* en el hash: una factura se autoriza ante la DGI DÍAS
     * después de emitida, sin que cambie ningún otro campo de la cabecera.
     */
    public function test_un_cambio_solo_en_campos_fe_se_detecta_y_remachea(): void
    {
        // 5 facturas de baseline (no 1) — con baseline=1, actualizar la única fila es 100% de
        // cambio, siempre por encima del umbral del 20% del circuit breaker, sin importar que el
        // cambio sea perfectamente normal. 1/5=20% (no dispara, el check es changeRatio > umbral,
        // estrictamente mayor). Bug real de dimensionamiento del fixture encontrado en esta sesión
        // (2026-09-14) — no es un bug del circuit breaker en sí.
        Cliente::query()->create(['icg_codcliente' => 55, 'razon_social' => 'Cliente Test']);
        $baseline = array_map(
            static fn (int $i): FacturaRow => new FacturaRow('A', $i, '1', 55, null, null, 100.0, 7.0, 107.0, "F00{$i}"),
            range(1, 5),
        );
        $fake = new MutableFakeFacturaExtractor(new ExtractedFacturas($baseline));
        $this->app->instance(FacturaExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $conFe = new ExtractedFacturas([
            ...array_map(
                static fn (int $i): FacturaRow => new FacturaRow('A', $i, '1', 55, null, null, 100.0, 7.0, 107.0, "F00{$i}"),
                range(2, 5),
            ),
            new FacturaRow('A', 1, '1', 55, null, null, 100.0, 7.0, 107.0, 'F001',
                feQr: 'https://dgi-fep.mef.gob.pa/qr/abc', feEstado: 'PROCESADO'),
        ]);
        $fake->setResult($conFe);
        // NUNCA guardar el PendingCommand en una variable y llamar assertExitCode() en una
        // sentencia aparte — PendingCommand::assertExitCode() solo GUARDA la expectativa, no
        // ejecuta el comando (ver vendor/laravel/framework/.../Testing/PendingCommand.php). La
        // ejecución real ocurre en run(), disparado por __destruct() si nunca se llamó run() antes
        // — con el comando en una variable que sigue viva, ese __destruct() no dispara hasta el
        // final del método de test, DESPUÉS de que las aserciones de abajo ya corrieron contra
        // datos viejos. El one-liner (encadenado) sí ejecuta de inmediato: el objeto temporal se
        // destruye al terminar la sentencia. Bug real encontrado en esta sesión (2026-09-14).
        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $runs = SyncRun::query()->orderBy('id')->get();
        $this->assertSame(1, $runs->last()->mapeados); // sí remacheó la única factura, que cambió

        // Pre-QA 2026-09-14 — bug real de la ASERCIÓN, no del código: sin filtrar por
        // icg_numfactura, `firstOrFail()` depende del orden físico del heap de Postgres, que
        // cambia tras el UPDATE de la factura #1 (nueva versión de tupla, MVCC) — el SELECT sin
        // ORDER BY dejó de devolver la factura #1 y devolvía la #2 (fe_qr/fe_estado null),
        // haciendo fallar el test aunque la corrección de fe_* funciona correctamente (verificado
        // con las 5 filas — solo la #1 tiene fe_qr/fe_estado seteados).
        $factura = Factura::query()->where('icg_numserie', 'A')->where('icg_numfactura', 1)->firstOrFail();
        $this->assertSame('https://dgi-fep.mef.gob.pa/qr/abc', $factura->fe_qr);
        $this->assertSame('PROCESADO', $factura->fe_estado);
    }

    public function test_circuit_breaker_frena_un_cambio_anormal_y_no_matchea(): void
    {
        $facturasIniciales = array_map(
            static fn (int $i): FacturaRow => new FacturaRow('A', $i, '1', null, null, null, 100.0, 7.0, 107.0, "F00{$i}"),
            range(1, 4),
        );
        $fake = new MutableFakeFacturaExtractor(new ExtractedFacturas($facturasIniciales));
        $this->app->instance(FacturaExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // 3 de 4 (75% > 20%) cambian de total_neto.
        $facturasCambiadas = [
            new FacturaRow('A', 1, '1', null, null, null, 999.0, 7.0, 1006.0, 'F001'),
            new FacturaRow('A', 2, '1', null, null, null, 999.0, 7.0, 1006.0, 'F002'),
            new FacturaRow('A', 3, '1', null, null, null, 999.0, 7.0, 1006.0, 'F003'),
            new FacturaRow('A', 4, '1', null, null, null, 100.0, 7.0, 107.0, 'F004'),
        ];
        $fake->setResult(new ExtractedFacturas($facturasCambiadas));
        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);

        // El staging sí se actualizó (igual que en Productos) — el circuit breaker frena el
        // Matcher, no el staging.
        $factura1 = Factura::query()->where('icg_numserie', 'A')->where('icg_numfactura', 1)->firstOrFail();
        $this->assertSame('999.00', $factura1->total_neto);
    }

    public function test_dry_run_nunca_evalua_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-facturas', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function sampleExtraction(): ExtractedFacturas
    {
        return new ExtractedFacturas([
            new FacturaRow('A', 1, '1', 55, null, null, 100.0, 7.0, 107.0, 'F001'),
        ]);
    }
}
