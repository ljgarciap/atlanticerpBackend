<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\MovimientoExtractorContract;
use App\Modules\Legacy\Contracts\MovimientoVerificationExtractorContract;
use App\Modules\Legacy\Extractors\MovimientoExtractor;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\MovimientoStagingLoader;
use App\Modules\Legacy\Services\MovimientoStagingVerifier;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Unit\Legacy\MovimientoExtractorTest;

/**
 * SCRUM-1136 F2 — loader reanudable por llave, `legacy:sync-movimientos` y `legacy:verify-movimientos` con
 * el extractor fakeado (sin SQL Server real). Los comandos hacen `Tenant::forgetCurrent()` al terminar:
 * `reactivateTenant()` lo vuelve a prender antes de asserts que dependan de `getTable()`.
 *
 * Las columnas `double` se comparan con `(float)`/assertEquals: PDO de Postgres las devuelve como texto en
 * el contenedor del gate y como float en el PHP del host.
 */
final class MovimientoStagingTest extends FeatureTestCase
{
    private const TABLA = 'atlantic_legacy.movimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function rows(array $ids, string $tipo = 'REG', float $unidades = 1.0): array
    {
        return array_map(
            static fn (int $id): array => MovimientoExtractor::mapRow(MovimientoExtractorTest::rawRow($id, ['TIPO' => $tipo, 'UNIDADES' => $unidades])),
            $ids,
        );
    }

    /** @return list<array<string, mixed>> filas mínimas (solo ID), baratas de generar en volumen */
    private static function minimalRows(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = MovimientoExtractor::mapRow(['ID' => $i, 'TIPO' => 'REG', 'UNIDADES' => 1]);
        }

        return $out;
    }

    private function fetcher(FakeMovimientoExtractor $fake): callable
    {
        return static fn (int $afterId, int $limit): array => $fake->extractAfter($afterId, $limit);
    }

    // ---- loader ---------------------------------------------------------------------------------------

    public function test_carga_persiste_las_30_columnas(): void
    {
        $fake = new FakeMovimientoExtractor(self::rows([7]));

        $r = (new MovimientoStagingLoader())->load($this->fetcher($fake), 10);

        $this->assertSame(1, $r['cargados']);
        $row = DB::table(self::TABLA)->where('icg_id', 7)->first();
        $this->assertNotNull($row);
        $this->assertSame('AL4', $row->codalmacenorigen);
        $this->assertSame(1234, (int) $row->codarticulo);
        $this->assertEquals(1.0, (float) $row->unidades);
        $this->assertEquals(987654.0, (float) $row->numdoc);
        $this->assertSame('A1', $row->seriedoc);
        $this->assertSame('REG', $row->tipo);
        $this->assertNotNull($row->fecha);
        $this->assertNotNull($row->hora);
        $this->assertNull($row->color);
        $this->assertEquals(4.0, (float) $row->unid4);
        $this->assertSame(3, (int) $row->codformato);
    }

    public function test_la_tabla_tiene_las_32_columnas_de_icg_mas_las_propias(): void
    {
        $columnas = DB::table('information_schema.columns')
            ->where('table_schema', 'atlantic_legacy')->where('table_name', 'movimientos')
            ->pluck('column_name')->all();

        // 32 de ICG (ID -> icg_id) + id propio + created_at/updated_at.
        $this->assertCount(32 + 3, $columnas);
        $this->assertContains('icg_id', $columnas);
    }

    public function test_paginacion_por_llave_pagina_parcial(): void
    {
        $fake = new FakeMovimientoExtractor(self::rows([1, 2, 3, 4, 5]));

        $r = (new MovimientoStagingLoader())->load($this->fetcher($fake), 2);

        $this->assertSame(['extraidos' => 5, 'cargados' => 5, 'paginas' => 3], array_intersect_key($r, array_flip(['extraidos', 'cargados', 'paginas'])));
        $this->assertTrue($r['completo']);
        $this->assertSame([0, 2, 4], $fake->requestedAfterIds);
        $this->assertSame(5, DB::table(self::TABLA)->count());
    }

    public function test_pagina_exacta_pide_una_mas_y_termina_vacia(): void
    {
        $fake = new FakeMovimientoExtractor(self::rows([1, 2, 3, 4]));

        $r = (new MovimientoStagingLoader())->load($this->fetcher($fake), 2);

        $this->assertSame(4, $r['cargados']);
        $this->assertSame(2, $r['paginas']);
        $this->assertSame([0, 2, 4], $fake->requestedAfterIds, 'la 3a llamada (vacía) confirma el fin');
        $this->assertTrue($r['completo']);
    }

    public function test_huecos_en_id_no_se_saltan_ni_duplican(): void
    {
        $ids  = [3, 10, 11, 500, 501, 1830964];
        $fake = new FakeMovimientoExtractor(self::rows($ids));

        (new MovimientoStagingLoader())->load($this->fetcher($fake), 2);

        $this->assertSame($ids, DB::table(self::TABLA)->orderBy('icg_id')->pluck('icg_id')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame([0, 10, 500, 1830964], $fake->requestedAfterIds);
    }

    public function test_idempotente_segunda_corrida_no_trae_ni_duplica(): void
    {
        $loader = new MovimientoStagingLoader();
        $loader->load($this->fetcher(new FakeMovimientoExtractor(self::rows([1, 2]))), 10);

        // Segunda corrida sobre carga completa: reanuda desde MAX(icg_id), no trae nada nuevo.
        $fake = new FakeMovimientoExtractor(self::rows([1, 2]));
        $r    = $loader->load($this->fetcher($fake), 10);
        $this->assertSame(0, $r['extraidos']);
        $this->assertSame([2], $fake->requestedAfterIds);
        $this->assertSame(2, DB::table(self::TABLA)->count());
        $this->assertSame(2, $loader->maxCargado());
    }

    public function test_reanuda_desde_el_maximo_ya_cargado(): void
    {
        $loader = new MovimientoStagingLoader();
        $loader->load($this->fetcher(new FakeMovimientoExtractor(self::rows([1, 2, 3]))), 10);

        $fake = new FakeMovimientoExtractor(self::rows([1, 2, 3, 4, 5]));
        $r    = $loader->load($this->fetcher($fake), 10);

        $this->assertSame(3, $r['desde_id']);
        $this->assertSame(2, $r['cargados']);
        $this->assertSame(5, $r['ultimo_id']);
        $this->assertSame(5, DB::table(self::TABLA)->count());
    }

    public function test_max_paginas_frena_y_la_siguiente_corrida_continua(): void
    {
        $loader = new MovimientoStagingLoader();
        $fake   = new FakeMovimientoExtractor(self::rows([1, 2, 3, 4, 5]));

        $r = $loader->load($this->fetcher($fake), 2, 2);
        $this->assertSame(4, $r['cargados']);
        $this->assertFalse($r['completo']);

        $r = $loader->load($this->fetcher($fake), 2, 2);
        $this->assertSame(1, $r['cargados']);
        $this->assertTrue($r['completo']);
        $this->assertSame(5, DB::table(self::TABLA)->count());
    }

    public function test_caida_a_mitad_conserva_lo_confirmado_y_reanuda_sin_duplicar_ni_saltar(): void
    {
        $loader = new MovimientoStagingLoader();
        $rows   = self::rows([1, 2, 3, 4, 5, 6, 7]);

        // 3a llamada falla: las páginas [1,2] y [3,4] ya quedaron confirmadas.
        try {
            $loader->load($this->fetcher(new FakeMovimientoExtractor($rows, failOnCall: 3)), 2);
            $this->fail('Debió propagar la caída.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('perdida', $e->getMessage());
        }
        $this->assertSame(4, DB::table(self::TABLA)->count());
        $this->assertSame(4, $loader->maxCargado());

        $r = $loader->load($this->fetcher(new FakeMovimientoExtractor($rows)), 2);

        $this->assertSame(4, $r['desde_id']);
        $this->assertSame(3, $r['cargados']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], DB::table(self::TABLA)->orderBy('icg_id')->pluck('icg_id')->map(fn ($v): int => (int) $v)->all());
    }

    public function test_llave_nula_a_mitad_de_pagina_aborta_sin_cargar_esa_pagina(): void
    {
        $fake = new FakeMovimientoExtractor(self::rows([1, 2]));
        $bad  = static function (int $afterId, int $limit) use ($fake): array {
            $page = $fake->extractAfter($afterId, $limit);
            if ($page !== []) {
                // El extractor real mapea fila a fila: una llave nula lanza al armar la página.
                MovimientoExtractor::mapRow(MovimientoExtractorTest::rawRow(1, ['ID' => null]));
            }

            return $page;
        };

        try {
            (new MovimientoStagingLoader())->load($bad, 10);
            $this->fail('Debió abortar por llave nula.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Llave natural', $e->getMessage());
        }
        $this->assertSame(0, DB::table(self::TABLA)->count());
    }

    public function test_id_duplicado_en_la_misma_pagina_se_deduplica_y_gana_el_ultimo(): void
    {
        $page = [
            ...self::rows([1], 'REG', 1.0),
            ...self::rows([2], 'REG', 2.0),
            ...self::rows([2], 'ENV', 7.0),
        ];
        $calls = 0;
        $fetch = static function (int $afterId, int $limit) use ($page, &$calls): array {
            return $calls++ === 0 ? $page : [];
        };

        $r = (new MovimientoStagingLoader())->load($fetch, 10);

        $this->assertSame(3, $r['extraidos']);
        $this->assertSame(2, $r['cargados']);
        $this->assertSame(2, DB::table(self::TABLA)->count());
        $this->assertSame('ENV', DB::table(self::TABLA)->where('icg_id', 2)->value('tipo'));
        $this->assertEquals(7.0, (float) DB::table(self::TABLA)->where('icg_id', 2)->value('unidades'));
    }

    public function test_extractor_que_no_avanza_aborta_en_vez_de_ciclar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no avanzó');

        (new MovimientoStagingLoader())->load(static fn (int $a, int $l): array => self::rows([1]), 1);
    }

    // ---- legacy:sync-movimientos ----------------------------------------------------------------------

    private function bindExtractor(MovimientoExtractorContract $fake): void
    {
        $this->app->instance(MovimientoExtractorContract::class, $fake);
    }

    public function test_comando_real_stagea_y_registra_sync_run(): void
    {
        $this->bindExtractor(new FakeMovimientoExtractor(self::rows([1, 2, 3])));

        $this->artisan('legacy:sync-movimientos')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table(self::TABLA)->count());
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('movimientos', $run->entidad);
        $this->assertSame(3, $run->extraidos);
        $this->assertSame(3, $run->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_comando_segunda_corrida_no_duplica(): void
    {
        $this->bindExtractor(new FakeMovimientoExtractor(self::rows([1, 2, 3])));

        $this->artisan('legacy:sync-movimientos')->assertExitCode(0);
        $this->artisan('legacy:sync-movimientos')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table(self::TABLA)->count());
        $this->assertSame(2, SyncRun::query()->count());
    }

    public function test_comando_dry_run_revierte_y_no_registra_sync_run(): void
    {
        $this->bindExtractor(new FakeMovimientoExtractor(self::rows([1, 2, 3])));

        $this->artisan('legacy:sync-movimientos', ['--dry-run' => true])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, DB::table(self::TABLA)->count());
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_dry_run_se_limita_a_tres_paginas_por_defecto(): void
    {
        $fake = new FakeMovimientoExtractor(self::minimalRows(15001));
        $this->bindExtractor($fake);

        $this->artisan('legacy:sync-movimientos', ['--dry-run' => true])
            ->expectsOutputToContain('Corrida acotada por --max-paginas')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame([0, 5000, 10000], $fake->requestedAfterIds);
        $this->assertSame(0, DB::table(self::TABLA)->count());
    }

    public function test_dry_run_respeta_max_paginas_explicito(): void
    {
        $fake = new FakeMovimientoExtractor(self::minimalRows(5001));
        $this->bindExtractor($fake);

        $this->artisan('legacy:sync-movimientos', ['--dry-run' => true, '--max-paginas' => 1])->assertExitCode(0);

        $this->assertSame([0], $fake->requestedAfterIds);
    }

    public function test_max_paginas_carga_por_tandas_sin_duplicar(): void
    {
        $this->bindExtractor(new FakeMovimientoExtractor(self::minimalRows(5001)));

        $this->artisan('legacy:sync-movimientos', ['--max-paginas' => 1])->assertExitCode(0);
        $this->reactivateTenant();
        $this->assertSame(5000, DB::table(self::TABLA)->count());

        $this->artisan('legacy:sync-movimientos')->assertExitCode(0);
        $this->reactivateTenant();
        $this->assertSame(5001, DB::table(self::TABLA)->count());
        $this->assertSame([5000, 1], SyncRun::query()->orderBy('id')->pluck('cargados')->map(fn ($v): int => (int) $v)->all());
    }

    public function test_max_paginas_invalido_falla_sin_tocar_nada(): void
    {
        $this->artisan('legacy:sync-movimientos', ['--max-paginas' => 0])->assertExitCode(1);
        $this->artisan('legacy:sync-movimientos', ['--max-paginas' => 'abc'])->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_caida_a_mitad_deja_partial_y_la_siguiente_corrida_completa_sin_duplicar(): void
    {
        $rows = self::minimalRows(5003);
        $this->bindExtractor(new FakeMovimientoExtractor($rows, failOnCall: 2));

        $this->artisan('legacy:sync-movimientos')->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(5000, $run->cargados);
        $this->assertSame(1, $run->errores);
        $this->assertSame(5000, DB::table(self::TABLA)->count());

        $this->bindExtractor(new FakeMovimientoExtractor($rows));
        $this->artisan('legacy:sync-movimientos')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(5003, DB::table(self::TABLA)->count());
        $this->assertSame(5003, DB::table(self::TABLA)->distinct()->count('icg_id'));
        $ultima = SyncRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(3, $ultima->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
    }

    public function test_falla_en_la_primera_pagina_queda_failed(): void
    {
        $this->bindExtractor(new FakeMovimientoExtractor(self::rows([1, 2]), failOnCall: 1));

        $this->artisan('legacy:sync-movimientos')->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(SyncRun::STATUS_FAILED, SyncRun::query()->firstOrFail()->status);
    }

    // ---- legacy:verify-movimientos --------------------------------------------------------------------

    /**
     * @param array<string, array{filas: int, unidades: float}> $porTipo
     */
    private function bindVerification(int $total, ?int $min, ?int $max, array $porTipo): void
    {
        $this->app->instance(MovimientoVerificationExtractorContract::class, new class($total, $min, $max, $porTipo) implements MovimientoVerificationExtractorContract {
            /** @param array<string, array{filas: int, unidades: float}> $porTipo */
            public function __construct(private int $total, private ?int $min, private ?int $max, private array $porTipo)
            {
            }

            public function resumen(): array
            {
                return ['total' => $this->total, 'min_id' => $this->min, 'max_id' => $this->max, 'por_tipo' => $this->porTipo];
            }
        });
    }

    private function stageMixed(): void
    {
        (new MovimientoStagingLoader())->load(
            $this->fetcher(new FakeMovimientoExtractor([
                ...self::rows([1, 2], 'REG', 2.5),
                ...self::rows([3], 'TMP', -1.0),
            ])),
            10,
        );
    }

    public function test_verify_ok_y_no_escribe(): void
    {
        $this->stageMixed();
        $runsBefore = SyncRun::query()->count();
        $this->bindVerification(3, 1, 3, ['REG' => ['filas' => 2, 'unidades' => 5.0], 'TMP' => ['filas' => 1, 'unidades' => -1.0]]);

        $this->artisan('legacy:verify-movimientos')
            ->expectsOutputToContain('Verificación OK')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame($runsBefore, SyncRun::query()->count());
        $this->assertSame(3, DB::table(self::TABLA)->count());
    }

    public function test_verify_falla_si_la_suma_de_unidades_difiere(): void
    {
        $this->stageMixed();
        $this->bindVerification(3, 1, 3, ['REG' => ['filas' => 2, 'unidades' => 99.0], 'TMP' => ['filas' => 1, 'unidades' => -1.0]]);

        $this->artisan('legacy:verify-movimientos')->assertExitCode(1);
    }

    public function test_verify_falla_si_la_carga_esta_incompleta(): void
    {
        $this->stageMixed();
        $this->bindVerification(10, 1, 50, ['REG' => ['filas' => 9, 'unidades' => 5.0], 'TMP' => ['filas' => 1, 'unidades' => -1.0]]);

        $this->artisan('legacy:verify-movimientos')->assertExitCode(1);
    }

    public function test_verify_staging_agrupa_nulos_como_vacio(): void
    {
        (new MovimientoStagingLoader())->load(
            $this->fetcher(new FakeMovimientoExtractor([MovimientoExtractor::mapRow(['ID' => 9, 'UNIDADES' => 2])])),
            10,
        );

        $resumen = (new MovimientoStagingVerifier())->resumen();

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(9, $resumen['min_id']);
        $this->assertEquals(2.0, $resumen['por_tipo']['']['unidades']);
    }
}
