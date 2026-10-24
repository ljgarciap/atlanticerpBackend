<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ClienteRawExtractorContract;
use App\Modules\Legacy\Contracts\ClienteRawVerificationExtractorContract;
use App\Modules\Legacy\Contracts\ContactoClienteRawExtractorContract;
use App\Modules\Legacy\Extractors\ClienteRawExtractor;
use App\Modules\Legacy\Extractors\ContactoClienteRawExtractor;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteRawStagingLoader;
use App\Modules\Legacy\Support\ClienteRawColumnas;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1137 F1 — `legacy:sync-clientes-raw`, `legacy:verify-clientes-raw` y `ClienteRawStagingLoader` con los
 * extractores fakeados (sin SQL Server real). El comando hace `Tenant::forgetCurrent()` al terminar:
 * `reactivateTenant()` lo vuelve a prender antes de asserts que dependan de `getTable()`.
 */
final class LegacySyncClientesRawCommandTest extends FeatureTestCase
{
    private const T_CLI = 'atlantic_legacy.clientes_raw';

    private const T_CON = 'atlantic_legacy.contactos_cliente_raw';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_el_extractor_real_no_pide_a_icg_las_columnas_excluidas(): void
    {
        $esquema = [];
        foreach (['CODCLIENTE', 'CIF', 'NIF20', 'VERSION', ...ClienteRawColumnas::EXCLUIDAS] as $nombre) {
            $esquema[] = (object) ['COLUMN_NAME' => $nombre, 'DATA_TYPE' => $nombre === 'VERSION' ? 'timestamp' : 'nvarchar'];
        }
        $sqlPagina = null;
        $conn = \Mockery::mock();
        $conn->shouldReceive('select')->andReturnUsing(function (string $sql) use ($esquema, &$sqlPagina) {
            if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                return $esquema;
            }
            $sqlPagina = $sql;

            return [];
        });
        DB::shouldReceive('connection')->with('legacy_icg')->andReturn($conn);

        (new ClienteRawExtractor())->extractPage(0, 10);

        $this->assertNotNull($sqlPagina);
        foreach ([...ClienteRawColumnas::EXCLUIDAS, 'VERSION'] as $excluida) {
            $this->assertStringNotContainsString('[' . $excluida . ']', $sqlPagina);
        }
        $this->assertStringContainsString('[CODCLIENTE]', $sqlPagina);
        $this->assertStringContainsString('[NIF20]', $sqlPagina);
    }

    /** @return list<array<string, mixed>> */
    private static function clientes(int $n, string $nombre = 'Cliente'): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ClienteRawExtractor::mapRow([
                'CODCLIENTE' => $i, 'NOMBRECLIENTE' => "{$nombre} {$i}", 'CIF' => "RUC-{$i}", 'NIF20' => "RUC-{$i}-2-2026",
                'RIESGOCONCEDIDO' => $i * 1.5, 'ESCLIENTEDELGRUPO' => 1, 'FECHAMODIFICADO' => '2026-01-02 03:04:05',
                'OBSERVACIONES' => "Nota {$i}",
            ]);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function contactos(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ContactoClienteRawExtractor::mapRow([
                'ID' => $i, 'CODCLIENTE' => $i, 'CARGO' => 'Gerente', 'NOMBRE' => "Contacto {$i}",
                'E_MAIL' => "c{$i}@example.com", 'FACTURACION' => 1, 'TESORERIA' => 0,
            ]);
        }

        return $rows;
    }

    private function bind(?object $clientes = null, ?object $contactos = null): void
    {
        $this->app->instance(ClienteRawExtractorContract::class, $clientes ?? new FakeClienteRawExtractor(self::clientes(3)));
        $this->app->instance(ContactoClienteRawExtractorContract::class, $contactos ?? new FakeClienteRawExtractor(self::contactos(2)));
    }

    public function test_corrida_real_stagea_las_dos_tablas_y_registra_una_sola_corrida(): void
    {
        $this->bind();

        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table(self::T_CLI)->count());
        $this->assertSame(2, DB::table(self::T_CON)->count());

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, SyncRun::query()->count());
        $this->assertSame('clientes_raw', $run->entidad);
        $this->assertSame(5, $run->extraidos);
        $this->assertSame(5, $run->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);

        $fila = DB::table(self::T_CLI)->where('codcliente', 2)->first();
        $this->assertSame('Cliente 2', $fila->nombrecliente);
        $this->assertSame('RUC-2-2-2026', $fila->nif20);
        $this->assertSame(3.0, (float) $fila->riesgoconcedido);
        $this->assertTrue((bool) $fila->esclientedelgrupo);
        $this->assertSame(64, strlen((string) $fila->source_hash));
        $this->assertSame('c1@example.com', DB::table(self::T_CON)->where('id', 1)->value('e_mail'));
    }

    public function test_segunda_corrida_no_cambia_nada_ni_reescribe_filas(): void
    {
        $this->bind();
        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        // Se "envejece" updated_at a mano: si la segunda corrida reescribiera la fila, lo pisaría.
        DB::table(self::T_CLI)->update(['updated_at' => '2020-01-01 00:00:00']);
        DB::table(self::T_CON)->update(['updated_at' => '2020-01-01 00:00:00']);
        $snapshot = static fn (): array => DB::table(self::T_CLI)->orderBy('codcliente')->get(['id', 'codcliente', 'source_hash', 'created_at'])
            ->map(static fn (object $r): array => (array) $r)->all();
        $antes = $snapshot();

        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(3, DB::table(self::T_CLI)->count());
        $this->assertSame(2, DB::table(self::T_CON)->count());
        $this->assertSame($antes, $snapshot());
        $this->assertSame(0, DB::table(self::T_CLI)->where('updated_at', '>', '2020-01-02')->count());
        $this->assertSame(0, DB::table(self::T_CON)->where('updated_at', '>', '2020-01-02')->count());
        $this->assertSame(2, SyncRun::query()->count());
    }

    public function test_loader_reporta_cambiados_solo_para_filas_que_cambiaron(): void
    {
        $loader = new ClienteRawStagingLoader();
        $fetch  = static fn (array $rows): callable => static fn (int $o, int $l): array => array_values(array_slice($rows, $o, $l));

        $primera = $loader->loadPaginated('clientes_raw', $fetch(self::clientes(4)));
        $this->assertSame(['extraidos' => 4, 'cargados' => 4, 'cambiados' => 4, 'paginas' => 1], $primera);

        $igual = $loader->loadPaginated('clientes_raw', $fetch(self::clientes(4)));
        $this->assertSame(['extraidos' => 4, 'cargados' => 4, 'cambiados' => 0, 'paginas' => 1], $igual);

        // Cambia solo el cliente 3.
        $rows = self::clientes(4);
        $rows[2] = ClienteRawExtractor::mapRow(['CODCLIENTE' => 3, 'NOMBRECLIENTE' => 'Renombrado']);
        $cambio = $loader->loadPaginated('clientes_raw', $fetch($rows));
        $this->assertSame(1, $cambio['cambiados']);
        $this->assertSame('Renombrado', DB::table(self::T_CLI)->where('codcliente', 3)->value('nombrecliente'));
        $this->assertSame('Cliente 1', DB::table(self::T_CLI)->where('codcliente', 1)->value('nombrecliente'));
        $this->assertSame(4, DB::table(self::T_CLI)->count());
    }

    public function test_loader_parte_los_inserts_por_el_limite_de_parametros_y_pagina(): void
    {
        $loader = new ClienteRawStagingLoader();
        $rows   = self::clientes(700);

        // 700 filas x 116 parámetros superan los 30.000 -> varios INSERT; páginas de 300 -> 3 páginas.
        $res = $loader->loadPaginated('clientes_raw', static fn (int $o, int $l): array => array_values(array_slice($rows, $o, $l)), 300);

        $this->assertSame(['extraidos' => 700, 'cargados' => 700, 'cambiados' => 700, 'paginas' => 3], $res);
        $this->assertSame(700, DB::table(self::T_CLI)->count());
    }

    public function test_llave_repetida_en_una_pagina_no_rompe_el_upsert(): void
    {
        $rows = [...self::clientes(2), ClienteRawExtractor::mapRow(['CODCLIENTE' => 1, 'NOMBRECLIENTE' => 'Ultima'])];

        $res = (new ClienteRawStagingLoader())->loadPaginated('clientes_raw', static fn (int $o, int $l): array => $o === 0 ? $rows : []);

        $this->assertSame(2, $res['cargados']);
        $this->assertSame('Ultima', DB::table(self::T_CLI)->where('codcliente', 1)->value('nombrecliente'));
    }

    public function test_dry_run_revierte_todo_y_no_registra_corrida(): void
    {
        $this->bind();

        $this->artisan('legacy:sync-clientes-raw', ['--dry-run' => true])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, DB::table(self::T_CLI)->count());
        $this->assertSame(0, DB::table(self::T_CON)->count());
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corte_a_mitad_de_corrida_deja_partial_y_la_siguiente_completa_sin_duplicar(): void
    {
        // PAGE_SIZE = 2000: la 1ª página trae exactamente $limit filas (el loader pide la siguiente) y la 2ª
        // simula la caída de la conexión a ICG.
        $this->bind(new class implements ClienteRawExtractorContract {
            public function extractPage(int $offset, int $limit): array
            {
                if ($offset > 0) {
                    throw new RuntimeException('Conexión a ICG perdida (simulada).');
                }

                $out = [];
                for ($i = 1; $i <= $limit; $i++) {
                    $out[] = ClienteRawExtractor::mapRow(['CODCLIENTE' => $i, 'NOMBRECLIENTE' => "C{$i}"]);
                }

                return $out;
            }
        });

        $this->artisan('legacy:sync-clientes-raw')
            ->expectsOutputToContain('Conexión a ICG perdida')
            ->expectsOutputToContain('Falló la página que empieza en la fila 2000')
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(2000, $run->cargados);
        $this->assertSame(1, $run->errores);
        $this->assertSame(2000, DB::table(self::T_CLI)->count());
        $this->assertSame(0, DB::table(self::T_CON)->count(), 'Si clientes falla, contactos no corre');

        $this->bind();
        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(2000, DB::table(self::T_CLI)->count(), 'Los 3 clientes ya existen entre los 2000: no duplica');
        $this->assertSame(2, DB::table(self::T_CON)->count());
    }

    public function test_falla_en_la_primera_pagina_queda_failed(): void
    {
        $this->bind(new FakeClienteRawExtractor(self::clientes(2), failFromOffset: 0));

        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(SyncRun::STATUS_FAILED, SyncRun::query()->firstOrFail()->status);
    }

    public function test_contacto_con_id_nulo_falla_con_mensaje_claro_y_sin_imprimir_datos(): void
    {
        $this->bind(null, new class implements ContactoClienteRawExtractorContract {
            public function extractPage(int $offset, int $limit): array
            {
                return [ContactoClienteRawExtractor::mapRow([
                    'ID' => null, 'CODCLIENTE' => 9, 'NOMBRE' => 'Persona Secreta', 'E_MAIL' => 'secreto@example.com',
                ])];
            }
        });

        $this->artisan('legacy:sync-clientes-raw')
            ->expectsOutputToContain("Llave natural 'id' nula o inválida")
            ->doesntExpectOutputToContain('Persona Secreta')
            ->doesntExpectOutputToContain('secreto@example.com')
            ->assertExitCode(1);
        $this->reactivateTenant();

        // Clientes ya quedaron cargados; contactos nada: la corrida es partial.
        $this->assertSame(3, DB::table(self::T_CLI)->count());
        $this->assertSame(0, DB::table(self::T_CON)->count());
        $this->assertSame(SyncRun::STATUS_PARTIAL, SyncRun::query()->firstOrFail()->status);
    }

    public function test_la_salida_no_imprime_valores_de_columnas(): void
    {
        $this->bind(
            new FakeClienteRawExtractor([ClienteRawExtractor::mapRow([
                'CODCLIENTE' => 1, 'NOMBRECLIENTE' => 'NOMBRE-PRIVADO-XYZ', 'NUMCUENTA' => 'CTA-PRIV-1',
                'CODIGOIBAN' => 'IBAN-PRIVADO-456', 'CIF' => 'RUC-PRIV-789', 'E_MAIL' => 'privado@example.com',
            ])]),
        );

        $this->artisan('legacy:sync-clientes-raw')
            ->doesntExpectOutputToContain('NOMBRE-PRIVADO-XYZ')
            ->doesntExpectOutputToContain('CTA-PRIV-1')
            ->doesntExpectOutputToContain('IBAN-PRIVADO-456')
            ->doesntExpectOutputToContain('RUC-PRIV-789')
            ->doesntExpectOutputToContain('privado@example.com')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame('CTA-PRIV-1', DB::table(self::T_CLI)->value('numcuenta'), 'Sí se copia (bancarias aprobadas), solo no se imprime');
    }

    public function test_un_error_de_base_de_datos_no_imprime_el_sql_ni_los_valores(): void
    {
        // cif es varchar(12): un valor más largo hace fallar el INSERT (SQLSTATE 22001) y el mensaje nativo de
        // Laravel trae el SQL con los bindings.
        $this->bind(new FakeClienteRawExtractor([ClienteRawExtractor::mapRow([
            'CODCLIENTE' => 1, 'NOMBRECLIENTE' => 'NOMBRE-PRIVADO-XYZ', 'CIF' => 'ESTE-VALOR-ES-DEMASIADO-LARGO-PARA-CIF',
        ])]));

        $this->artisan('legacy:sync-clientes-raw')
            ->expectsOutputToContain('SQLSTATE 22001')
            ->doesntExpectOutputToContain('ESTE-VALOR-ES-DEMASIADO-LARGO')
            ->doesntExpectOutputToContain('NOMBRE-PRIVADO-XYZ')
            ->doesntExpectOutputToContain('insert into')
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(SyncRun::STATUS_FAILED, SyncRun::query()->firstOrFail()->status);
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->artisan('legacy:sync-clientes-raw', ['--tenant' => 'no-existe'])->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_las_tablas_no_tienen_columnas_sensibles_excluidas(): void
    {
        $cols = DB::table('information_schema.columns')
            ->where('table_schema', 'atlantic_legacy')
            ->whereIn('table_name', ['clientes_raw', 'contactos_cliente_raw'])
            ->pluck('column_name')
            ->map(static fn (string $c): string => strtolower($c))
            ->all();

        $this->assertNotEmpty($cols);
        foreach ([...ClienteRawColumnas::EXCLUIDAS, 'VERSION'] as $excluida) {
            $this->assertNotContains(strtolower($excluida), $cols);
        }
    }

    public function test_ninguna_ruta_expone_las_tablas_raw(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('clientes_raw', $route->uri());
            $this->assertStringNotContainsString('clientes-raw', $route->uri());
            $this->assertStringNotContainsString('contactos_cliente_raw', $route->uri());
            $this->assertStringNotContainsString('contactos-cliente-raw', $route->uri());
        }
    }

    public function test_verify_ok_cuando_coinciden_y_no_escribe(): void
    {
        $this->bind();
        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();
        $runsBefore = SyncRun::query()->count();

        $this->app->instance(ClienteRawVerificationExtractorContract::class, new FakeClienteRawVerification(
            ['clientes_raw' => 3, 'contactos_cliente_raw' => 2],
            ['clientes_raw' => [1, 2, 3], 'contactos_cliente_raw' => [1, 2]],
        ));

        $this->artisan('legacy:verify-clientes-raw')
            ->expectsOutputToContain('Verificación OK')
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame($runsBefore, SyncRun::query()->count());
    }

    public function test_verify_reporta_una_fila_borrada_en_icg_solo_con_llaves(): void
    {
        $this->bind();
        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        // En ICG ya no existe el cliente 2 (sigue en staging, que es upsert-only).
        $this->app->instance(ClienteRawVerificationExtractorContract::class, new FakeClienteRawVerification(
            ['clientes_raw' => 2, 'contactos_cliente_raw' => 2],
            ['clientes_raw' => [1, 3], 'contactos_cliente_raw' => [1, 2]],
        ));

        $this->artisan('legacy:verify-clientes-raw')
            ->expectsOutputToContain('clientes_raw: llaves en staging y NO en ICG (borradas en ICG): 2')
            ->doesntExpectOutputToContain('Cliente 2')
            ->doesntExpectOutputToContain('example.com')
            ->assertExitCode(1);
    }

    public function test_verify_reporta_filas_de_icg_que_faltan_en_staging(): void
    {
        $this->bind();
        $this->artisan('legacy:sync-clientes-raw')->assertExitCode(0);
        $this->reactivateTenant();

        $this->app->instance(ClienteRawVerificationExtractorContract::class, new FakeClienteRawVerification(
            ['clientes_raw' => 4, 'contactos_cliente_raw' => 2],
            ['clientes_raw' => [1, 2, 3, 4], 'contactos_cliente_raw' => [1, 2]],
        ));

        $this->artisan('legacy:verify-clientes-raw')
            ->expectsOutputToContain('clientes_raw: llaves en ICG y NO en staging: 4')
            ->assertExitCode(1);
    }

    public function test_verify_falla_si_icg_no_responde(): void
    {
        $this->app->instance(ClienteRawVerificationExtractorContract::class, new class implements ClienteRawVerificationExtractorContract {
            public function conteos(): array
            {
                throw new RuntimeException('ICG caído (simulado).');
            }

            public function llaves(string $entidad): array
            {
                return [];
            }
        });

        $this->artisan('legacy:verify-clientes-raw')
            ->expectsOutputToContain('No se pudo leer ICG')
            ->assertExitCode(1);
    }
}

/** Test double de un extractor paginado de clientes/contactos: sirve filas ya mapeadas con `array_slice`. */
final class FakeClienteRawExtractor implements ClienteRawExtractorContract, ContactoClienteRawExtractorContract
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?int $failFromOffset = null,
    ) {
    }

    public function extractPage(int $offset, int $limit): array
    {
        if ($this->failFromOffset !== null && $offset >= $this->failFromOffset) {
            throw new RuntimeException('Conexión a ICG perdida (simulada).');
        }

        return array_values(array_slice($this->rows, $offset, $limit));
    }
}

/** Fake de los agregados de ICG para `legacy:verify-clientes-raw`. */
final class FakeClienteRawVerification implements ClienteRawVerificationExtractorContract
{
    /**
     * @param array<string, int>       $conteos
     * @param array<string, list<int>> $llaves
     */
    public function __construct(private readonly array $conteos, private readonly array $llaves)
    {
    }

    public function conteos(): array
    {
        return $this->conteos;
    }

    public function llaves(string $entidad): array
    {
        return $this->llaves[$entidad] ?? [];
    }
}
