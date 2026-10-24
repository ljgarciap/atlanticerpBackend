<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlmacenExtractorContract;
use App\Modules\Legacy\Contracts\AlmacenVerificationExtractorContract;
use App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\InventarioCabExtractorContract;
use App\Modules\Legacy\Contracts\StockAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\TraspasoCabExtractorContract;
use App\Modules\Legacy\Extractors\AlmacenExtractor;
use App\Modules\Legacy\Extractors\StockAlmacenExtractor;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1136 F1 — `legacy:sync-almacenes` y `legacy:verify-almacenes` con los extractores fakeados (sin
 * SQL Server real). El comando hace `Tenant::forgetCurrent()` al terminar: `reactivateTenant()` lo vuelve
 * a prender antes de asserts que dependan de `getTable()`.
 */
final class LegacySyncAlmacenesCommandTest extends FeatureTestCase
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

    /** @return list<array<string, mixed>> */
    private static function stockRows(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = StockAlmacenExtractor::mapRow([
                'CODARTICULO' => (string) $i, 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4', 'STOCK' => $i,
            ]);
        }

        return $rows;
    }

    private function bindAll(object $fake): void
    {
        foreach ([
            AlmacenExtractorContract::class, StockAlmacenExtractorContract::class, CostoAlmacenExtractorContract::class,
            TraspasoCabExtractorContract::class, InventarioCabExtractorContract::class,
        ] as $contract) {
            $this->app->instance($contract, $fake);
        }
    }

    public function test_corrida_real_stagea_y_registra_sync_run(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(3)));

        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table('atlantic_legacy.stock_almacen')->count());
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('stock_almacen', $run->entidad);
        $this->assertSame(3, $run->extraidos);
        $this->assertSame(3, $run->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_segunda_corrida_inmediata_no_cambia_conteos(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(3)));

        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table('atlantic_legacy.stock_almacen')->count());
        $this->assertSame(2, SyncRun::query()->count());
    }

    public function test_dry_run_revierte_todo_y_no_registra_sync_run(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(3)));

        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, DB::table('atlantic_legacy.stock_almacen')->count());
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_sin_entidad_corre_las_cinco_y_registra_cinco_corridas(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor([]));

        $this->artisan('legacy:sync-almacenes')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertEqualsCanonicalizing(
            ['almacenes', 'stock_almacen', 'costo_almacen', 'traspasos', 'inventarios'],
            SyncRun::query()->pluck('entidad')->all(),
        );
    }

    public function test_caida_a_mitad_deja_partial_y_la_siguiente_corrida_completa_sin_duplicar(): void
    {
        // PAGE_SIZE de producción es 5000: la 1ª página trae exactamente $limit filas (el loader pide la
        // siguiente) y la 2ª simula la caída de la conexión a ICG.
        $this->bindAll(new class implements
            AlmacenExtractorContract, StockAlmacenExtractorContract, CostoAlmacenExtractorContract,
            TraspasoCabExtractorContract, InventarioCabExtractorContract {
            public function extractPage(int $offset, int $limit): array
            {
                if ($offset > 0) {
                    throw new \RuntimeException('Conexión a ICG perdida (simulada).');
                }

                $out = [];
                for ($i = 1; $i <= $limit; $i++) {
                    $out[] = StockAlmacenExtractor::mapRow([
                        'CODARTICULO' => (string) $i, 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4', 'STOCK' => 1,
                    ]);
                }

                return $out;
            }
        });

        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(5000, $run->cargados);
        $this->assertSame(1, $run->errores);
        $this->assertSame(5000, DB::table('atlantic_legacy.stock_almacen')->count());

        // La siguiente corrida (ICG ya disponible, 3 filas) completa y no duplica las existentes.
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(3)));
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(5000, DB::table('atlantic_legacy.stock_almacen')->count());
    }

    public function test_falla_en_la_primera_pagina_queda_failed(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(2), failFromOffset: 0));

        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(SyncRun::STATUS_FAILED, SyncRun::query()->firstOrFail()->status);
    }

    public function test_entidad_invalida_falla_sin_tocar_nada(): void
    {
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'movimientos'])->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_verify_ok_lista_huerfanos_y_no_escribe(): void
    {
        $loaderRows = [
            StockAlmacenExtractor::mapRow(['CODARTICULO' => '1', 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4', 'STOCK' => 5]),
            StockAlmacenExtractor::mapRow(['CODARTICULO' => '2', 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'A1', 'STOCK' => -1]),
        ];
        $this->bindAll(new FakeIcgPaginatedExtractor($loaderRows));
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->bindAll(new FakeIcgPaginatedExtractor([AlmacenExtractor::mapRow(['CODALMACEN' => 'AL4'])]));
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'almacenes'])->assertExitCode(0);
        $this->reactivateTenant();
        $runsBefore = SyncRun::query()->count();

        $this->app->instance(AlmacenVerificationExtractorContract::class, new FakeVerification(
            ['AL4' => ['filas' => 1, 'stock' => 5.0], 'A1' => ['filas' => 1, 'stock' => -1.0]],
            ['almacenes' => 1, 'costo_almacen' => 0, 'traspasos' => 0, 'inventarios' => 0],
        ));

        $this->artisan('legacy:verify-almacenes')
            ->expectsOutputToContain('huérfanos')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame($runsBefore, SyncRun::query()->count());
    }

    public function test_verify_falla_si_la_suma_de_stock_difiere(): void
    {
        $this->bindAll(new FakeIcgPaginatedExtractor(self::stockRows(2)));
        $this->artisan('legacy:sync-almacenes', ['--entidad' => 'stock_almacen'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->app->instance(AlmacenVerificationExtractorContract::class, new FakeVerification(
            ['AL4' => ['filas' => 2, 'stock' => 999.0]],
            ['almacenes' => 0, 'costo_almacen' => 0, 'traspasos' => 0, 'inventarios' => 0],
        ));

        $this->artisan('legacy:verify-almacenes')->assertExitCode(1);
    }
}

/** Fake de los agregados de ICG para `legacy:verify-almacenes`. */
final class FakeVerification implements AlmacenVerificationExtractorContract
{
    /**
     * @param array<string, array{filas: int, stock: float}> $stock
     * @param array<string, int>                             $conteos
     */
    public function __construct(private readonly array $stock, private readonly array $conteos)
    {
    }

    public function stockPorAlmacen(): array
    {
        return $this->stock;
    }

    public function conteos(): array
    {
        return $this->conteos;
    }
}
