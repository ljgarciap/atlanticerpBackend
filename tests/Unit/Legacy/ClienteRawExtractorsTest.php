<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Console\Commands\LegacySyncClientesRawCommand;
use App\Modules\Legacy\Extractors\ClienteRawExtractor;
use App\Modules\Legacy\Extractors\ClienteRawVerificationExtractor;
use App\Modules\Legacy\Extractors\ContactoClienteRawExtractor;
use App\Modules\Legacy\Extractors\IcgTablaExtractor;
use App\Modules\Legacy\Services\ClienteRawStagingVerifier;
use App\Modules\Legacy\Support\ClienteRawColumnas;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SCRUM-1137 F1 — capa pura (sin DB, sin red) de la copia cruda de CLIENTES / CONTACTOSCLIENTES de ICG.
 * Los tests de "columnas" se anclan al esquema REAL volcado de ICG (`docs/architecture/icg-clientes-esquema-icg.txt`),
 * no a una lista escrita a mano: si alguien agrega al mapper o a la migración una columna sensible, fallan.
 */
final class ClienteRawExtractorsTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../docs/architecture/icg-clientes-esquema-icg.txt';

    private const MIG_CLIENTES  = __DIR__ . '/../../../database/migrations/tenant/2026_10_14_100000_create_legacy_clientes_raw_table.php';
    private const MIG_CONTACTOS = __DIR__ . '/../../../database/migrations/tenant/2026_10_14_100001_create_legacy_contactos_cliente_raw_table.php';

    /** Tipos de SQL Server que nunca se copian. */
    private const BINARIOS = ['image', 'timestamp', 'binary', 'varbinary', 'rowversion'];

    /**
     * @return array<string, list<array{name: string, type: string}>> tabla ICG => columnas del esquema real
     */
    private static function esquema(): array
    {
        $out = [];
        foreach (file(self::SCHEMA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_starts_with($line, 'SCH|')) {
                continue;
            }
            $p = explode('|', $line);
            if ($p[1] === 'PK' || $p[2] === '__filas') {
                continue;
            }
            $out[$p[1]][] = ['name' => $p[2], 'type' => $p[3]];
        }

        return $out;
    }

    /**
     * Columnas ICG que SÍ se copian de una tabla (minúscula): esquema real menos binarias, VERSION y la lista fija.
     *
     * @return list<string>
     */
    private static function columnasCopiables(string $tabla): array
    {
        $out = [];
        foreach (self::esquema()[$tabla] as $c) {
            if (in_array($c['type'], self::BINARIOS, true)
                || in_array($c['name'], [...ClienteRawColumnas::EXCLUIDAS, 'VERSION'], true)) {
                continue;
            }
            $out[] = strtolower($c['name']);
        }

        return $out;
    }

    /** Fila "cruda" de ICG con un valor para TODAS las columnas del esquema real (incluidas las excluidas). */
    private static function filaCompleta(string $tabla, int $llave): array
    {
        $row = [];
        foreach (self::esquema()[$tabla] as $c) {
            $row[$c['name']] = match ($c['type']) {
                'int', 'smallint' => 1,
                'float'           => 1.5,
                'bit'             => 1,
                'datetime'        => '2026-01-02 03:04:05',
                'image', 'timestamp' => 'BINARIO',
                default           => 'valor-' . $c['name'],
            };
        }
        $row[$tabla === 'CLIENTES' ? 'CODCLIENTE' : 'ID'] = $llave;

        return $row;
    }

    /** @return list<string> columnas declaradas en una migración (`$table->tipo('col'`), sin id/timestamps. */
    private static function columnasDeMigracion(string $path): array
    {
        preg_match_all("/\\\$table->(?:string|text|integer|smallInteger|boolean|double|timestampTz)\\('([a-z0-9_]+)'/", (string) file_get_contents($path), $m);

        return $m[1];
    }

    public function test_el_esquema_real_tiene_las_columnas_esperadas(): void
    {
        $esquema = self::esquema();

        $this->assertCount(119, $esquema['CLIENTES']);
        $this->assertCount(10, $esquema['CONTACTOSCLIENTES']);
        // 119 - FOTOCLIENTE - VERSION - (CVC, PASS, PASSWORDCOMMERCE, NUMTARJETA) - (USUARIO, TIPOTARJETA, TARCADUCIDAD) = 110.
        $this->assertCount(110, self::columnasCopiables('CLIENTES'));
        $this->assertCount(10, self::columnasCopiables('CONTACTOSCLIENTES'));
    }

    public function test_cliente_mapea_exactamente_las_columnas_copiables_del_esquema_real(): void
    {
        $mapped = ClienteRawExtractor::mapRow(self::filaCompleta('CLIENTES', 6889));

        $esperadas = [...self::columnasCopiables('CLIENTES'), 'source_hash'];
        $this->assertEqualsCanonicalizing($esperadas, array_keys($mapped));
        $this->assertSame(6889, $mapped['codcliente']);
    }

    public function test_cliente_ni_el_mapper_ni_la_migracion_contienen_columnas_excluidas(): void
    {
        $mapped = ClienteRawExtractor::mapRow(self::filaCompleta('CLIENTES', 1));
        $enMigracion = self::columnasDeMigracion(self::MIG_CLIENTES);

        foreach ([...ClienteRawColumnas::EXCLUIDAS, 'VERSION'] as $excluida) {
            $col = strtolower($excluida);
            $this->assertArrayNotHasKey($col, $mapped, "El mapper no debe copiar {$excluida}");
            $this->assertNotContains($col, $enMigracion, "La tabla clientes_raw no debe tener {$excluida}");
        }
        $this->assertSame(['CVC', 'PASS', 'PASSWORDCOMMERCE', 'NUMTARJETA', 'FOTOCLIENTE', 'USUARIO', 'TIPOTARJETA', 'TARCADUCIDAD'], ClienteRawColumnas::EXCLUIDAS);
    }

    public function test_las_migraciones_tienen_exactamente_las_columnas_copiables_del_esquema_real(): void
    {
        $this->assertEqualsCanonicalizing(
            [...self::columnasCopiables('CLIENTES'), 'source_hash'],
            self::columnasDeMigracion(self::MIG_CLIENTES),
        );
        $this->assertEqualsCanonicalizing(
            [...self::columnasCopiables('CONTACTOSCLIENTES'), 'source_hash'],
            self::columnasDeMigracion(self::MIG_CONTACTOS),
        );
    }

    public function test_cliente_tipa_y_normaliza_valores(): void
    {
        $row = ClienteRawExtractor::mapRow([
            'CODCLIENTE'     => '6889',
            'NOMBRECLIENTE'  => '  ACME S.A.  ',
            'CIF'            => ' 155716554-2 ',
            'NIF20'          => '155716554-2-2021',
            'OBSERVACIONES'  => "Línea 1\nLínea 2",
            'RIESGOCONCEDIDO' => '1500.5',
            'TIPO'           => 2,
            'NOCALCULARCARGO1ARTIC' => 0,
            'ESCLIENTEDELGRUPO' => 1,
            'FECHAMODIFICADO' => '2026-03-04 05:06:07',
            'ALIAS'          => '',
        ]);

        $this->assertSame(6889, $row['codcliente']);
        $this->assertSame('ACME S.A.', $row['nombrecliente']);
        $this->assertSame('155716554-2', $row['cif']);
        $this->assertSame('155716554-2-2021', $row['nif20']);
        $this->assertSame("Línea 1\nLínea 2", $row['observaciones']);
        $this->assertSame(1500.5, $row['riesgoconcedido']);
        $this->assertSame(2, $row['tipo']);
        $this->assertFalse($row['nocalcularcargo1artic']);
        $this->assertTrue($row['esclientedelgrupo']);
        $this->assertSame('2026-03-04 05:06:07', $row['fechamodificado']);
        $this->assertNull($row['alias']);
        $this->assertNull($row['mobil']);
    }

    public function test_cliente_sin_llave_aborta(): void
    {
        $this->expectException(RuntimeException::class);
        ClienteRawExtractor::mapRow(['CODCLIENTE' => null, 'NOMBRECLIENTE' => 'X']);
    }

    public function test_contacto_mapea_exactamente_las_columnas_del_esquema_real(): void
    {
        $mapped = ContactoClienteRawExtractor::mapRow(self::filaCompleta('CONTACTOSCLIENTES', 77));

        $this->assertEqualsCanonicalizing([...self::columnasCopiables('CONTACTOSCLIENTES'), 'source_hash'], array_keys($mapped));
        $this->assertSame(77, $mapped['id']);
        $this->assertTrue($mapped['facturacion']);
    }

    public function test_contacto_con_id_nulo_lanza_runtime_exception_clara_sin_filtrar_datos(): void
    {
        try {
            ContactoClienteRawExtractor::mapRow([
                'ID' => null, 'CODCLIENTE' => 5, 'NOMBRE' => 'Maria Perez', 'E_MAIL' => 'maria@example.com', 'TELEFONO' => '507-555-0000',
            ]);
            $this->fail('Debió lanzar RuntimeException por ID nulo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Llave natural 'id' nula o inválida", $e->getMessage());
            $this->assertStringContainsString('CONTACTOSCLIENTES', $e->getMessage());
            // No debe volcar datos personales del contacto.
            $this->assertStringNotContainsString('Maria', $e->getMessage());
            $this->assertStringNotContainsString('example.com', $e->getMessage());
            $this->assertStringNotContainsString('555', $e->getMessage());
        }
    }

    public function test_source_hash_es_estable_y_cambia_con_cualquier_columna(): void
    {
        $base = self::filaCompleta('CLIENTES', 10);

        $a = ClienteRawExtractor::mapRow($base);
        $b = ClienteRawExtractor::mapRow(array_reverse($base, true));
        $this->assertSame($a['source_hash'], $b['source_hash'], 'El orden de las columnas no cambia el hash');
        $this->assertSame(64, strlen($a['source_hash']));

        $base['CIF'] = 'OTRO';
        $this->assertNotSame($a['source_hash'], ClienteRawExtractor::mapRow($base)['source_hash']);
    }

    public function test_un_cambio_en_una_columna_excluida_no_cambia_el_hash(): void
    {
        $base = self::filaCompleta('CLIENTES', 10);
        $a    = ClienteRawExtractor::mapRow($base);

        $base['CVC'] = '999';
        $base['PASS'] = 'otra';
        $this->assertSame($a['source_hash'], ClienteRawExtractor::mapRow($base)['source_hash']);
    }

    public function test_selectable_columns_excluye_la_lista_fija_ademas_de_las_binarias(): void
    {
        $schemaRows = [];
        foreach (self::esquema()['CLIENTES'] as $c) {
            $schemaRows[] = ['COLUMN_NAME' => $c['name'], 'DATA_TYPE' => $c['type']];
        }

        $cols = IcgTablaExtractor::selectableColumns($schemaRows, ['CODCLIENTE'], ClienteRawColumnas::EXCLUIDAS);

        $this->assertCount(110, $cols);
        foreach ([...ClienteRawColumnas::EXCLUIDAS, 'VERSION'] as $excluida) {
            $this->assertNotContains($excluida, $cols);
        }
        // El SELECT que se manda a ICG tampoco las nombra.
        $sql = IcgTablaExtractor::pageQuery('CLIENTES', $cols, ['CODCLIENTE']);
        foreach ([...ClienteRawColumnas::EXCLUIDAS, 'VERSION'] as $excluida) {
            $this->assertStringNotContainsString('[' . $excluida . ']', $sql);
        }
        $this->assertStringContainsString('ORDER BY [CODCLIENTE] OFFSET ? ROWS FETCH NEXT ? ROWS ONLY', $sql);
    }

    public function test_selectable_columns_sin_lista_extra_mantiene_el_comportamiento_anterior(): void
    {
        $cols = IcgTablaExtractor::selectableColumns([
            ['COLUMN_NAME' => 'CODALMACEN', 'DATA_TYPE' => 'nvarchar'],
            ['COLUMN_NAME' => 'PASS', 'DATA_TYPE' => 'nvarchar'],
            ['COLUMN_NAME' => 'VERSION', 'DATA_TYPE' => 'timestamp'],
        ], ['CODALMACEN']);

        $this->assertSame(['CODALMACEN', 'PASS'], $cols);
    }

    public function test_verificador_compara_conteos_y_llaves(): void
    {
        $ok = ClienteRawStagingVerifier::compare(3, 3, [1, 2, 3], [3, 2, 1]);
        $this->assertTrue($ok['ok']);

        $borrada = ClienteRawStagingVerifier::compare(2, 3, [1, 3], [1, 2, 3]);
        $this->assertFalse($borrada['ok']);
        $this->assertSame([2], $borrada['solo_en_staging']);
        $this->assertSame([], $borrada['solo_en_icg']);

        $faltante = ClienteRawStagingVerifier::compare(3, 2, [1, 2, 3], [1, 3]);
        $this->assertFalse($faltante['ok']);
        $this->assertSame([2], $faltante['solo_en_icg']);
    }

    public function test_extractor_de_verificacion_solo_pide_conteos_y_llaves(): void
    {
        $this->assertSame('SELECT COUNT(*) AS N FROM CLIENTES', ClienteRawVerificationExtractor::countQuery('clientes_raw'));
        $this->assertSame('SELECT ID AS K FROM CONTACTOSCLIENTES WHERE ID IS NOT NULL', ClienteRawVerificationExtractor::keysQuery('contactos_cliente_raw'));
        $this->assertSame([5, 9], ClienteRawVerificationExtractor::mapKeys([(object) ['K' => '5'], ['k' => 9], (object) ['K' => null]]));
    }

    public function test_safe_message_no_filtra_el_sql_ni_los_valores_de_un_error_de_base_de_datos(): void
    {
        $pdo = new PDOException('SQLSTATE[23502]: Not null violation: DETAIL: Failing row contains (1, 4111111111111111)');
        $pdo->errorInfo = ['23502', 7, 'x'];
        $qe  = new QueryException('pgsql', 'insert into "x" ("numcuenta") values (?)', ['4111111111111111'], $pdo);

        $msg = LegacySyncClientesRawCommand::safeMessage($qe);

        $this->assertStringContainsString('SQLSTATE 23502', $msg);
        $this->assertStringNotContainsString('4111', $msg);
        $this->assertStringNotContainsString('insert into', $msg);

        // Una RuntimeException propia (llave nula) sí pasa tal cual.
        $this->assertSame('Llave nula', LegacySyncClientesRawCommand::safeMessage(new RuntimeException('Llave nula')));
    }
}
