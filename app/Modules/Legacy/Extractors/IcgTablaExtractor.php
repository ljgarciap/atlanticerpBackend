<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\IcgPaginatedExtractorContract;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F1 — base común de los extractores paginados de ICG (ALMACEN, STOCKS, COSTESPORALMACEN,
 * TRASPASOSCAB, INVENTARIOS). Misma separación que el resto del módulo: construcción de query y mapeo de
 * filas son `static` y puros (testeables sin red, ver tests/Unit/Legacy/AlmacenExtractorsTest.php); solo
 * `extractPage()` toca la conexión `legacy_icg` (usuario `illumiread`, SOLO SELECT).
 *
 * El esquema real de estas tablas no se pudo confirmar sin acceso a ICG, así que las columnas a leer se
 * descubren una vez por corrida desde INFORMATION_SCHEMA (legible por `illumiread`), excluyendo las
 * binarias (`VERSION` rompe `json_encode`). Los nombres salen de ahí, no del usuario, y se citan con
 * corchetes tras validar que sean identificadores simples.
 *
 * Paginación `ORDER BY` + `OFFSET…FETCH` por la llave natural: nada de traer la tabla completa en memoria
 * (host de 2 GB con historial de OOM); el loader carga cada página antes de pedir la siguiente.
 */
abstract class IcgTablaExtractor implements IcgPaginatedExtractorContract
{
    /** Tipos de SQL Server que no se copian (binarios). */
    private const BINARY_TYPES = ['binary', 'varbinary', 'image', 'timestamp', 'rowversion'];

    /** Columnas excluidas por nombre aunque su tipo no sea binario. */
    private const EXCLUDED_COLUMNS = ['VERSION'];

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** Nombre de la tabla en ICG. */
    abstract protected static function tabla(): string;

    /**
     * Columnas ICG de la llave natural, en el orden estable de paginación.
     *
     * @return list<string>
     */
    abstract protected static function orderBy(): array;

    /**
     * Columnas ICG adicionales a excluir por nombre en ESTA tabla (SCRUM-1137: tarjeta, credenciales, foto).
     * Por defecto ninguna; se suman a las binarias y a `EXCLUDED_COLUMNS` de la base.
     *
     * @return list<string>
     */
    protected static function columnasExcluidas(): array
    {
        return [];
    }

    /**
     * Pura — fila cruda de ICG a fila de staging.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    abstract public static function mapRow(array $row): array;

    public function extractPage(int $offset, int $limit): array
    {
        $db = DB::connection($this->connection);

        $this->columns ??= self::selectableColumns(
            $db->select(self::columnsQuery(), [static::tabla()]),
            static::orderBy(),
            static::columnasExcluidas(),
        );

        $rows = $db->select(self::pageQuery(static::tabla(), $this->columns, static::orderBy()), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): array => static::mapRow((array) $row),
            $rows,
        ));
    }

    public static function columnsQuery(): string
    {
        return 'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS '
            . "WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION";
    }

    /**
     * Pura. Devuelve las columnas copiables (sin binarias ni excluidas por nombre).
     *
     * @param array<int, object|array<string, mixed>> $schemaRows filas de `columnsQuery()`
     * @param list<string>                            $requiredKeys columnas que deben existir (llave natural)
     * @param list<string>                            $extraExcluded columnas a excluir por nombre además de las de la base
     * @return list<string>
     */
    public static function selectableColumns(array $schemaRows, array $requiredKeys = [], array $extraExcluded = []): array
    {
        $excluded = array_map('strtoupper', [...self::EXCLUDED_COLUMNS, ...$extraExcluded]);
        $columns  = [];
        foreach ($schemaRows as $schemaRow) {
            $schemaRow = array_change_key_case((array) $schemaRow, CASE_UPPER);
            $name      = (string) ($schemaRow['COLUMN_NAME'] ?? '');
            $type      = strtolower((string) ($schemaRow['DATA_TYPE'] ?? ''));

            if ($name === '' || in_array($type, self::BINARY_TYPES, true)) {
                continue;
            }
            if (in_array(strtoupper($name), $excluded, true)) {
                continue;
            }
            $columns[] = $name;
        }

        $upper = array_map('strtoupper', $columns);
        foreach ($requiredKeys as $key) {
            if (! in_array(strtoupper($key), $upper, true)) {
                throw new RuntimeException(
                    "La tabla de ICG no tiene la columna de llave natural '{$key}'; revisar el esquema real."
                );
            }
        }

        return $columns;
    }

    /**
     * Pura. `OFFSET…FETCH` de SQL Server exige `ORDER BY`; se ordena por la llave natural completa.
     *
     * @param list<string> $columns
     * @param list<string> $orderBy
     */
    public static function pageQuery(string $tabla, array $columns, array $orderBy): string
    {
        if ($columns === [] || $orderBy === []) {
            throw new RuntimeException('pageQuery requiere columnas y un ORDER BY.');
        }

        $select = implode(', ', array_map(self::quote(...), $columns));
        $order  = implode(', ', array_map(self::quote(...), $orderBy));

        return 'SELECT ' . $select . ' FROM [dbo].' . self::quote($tabla)
            . ' ORDER BY ' . $order . ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
    }

    /** Cita un identificador simple (`[A-Za-z0-9_]+`) con corchetes de SQL Server; reutilizado por `MovimientoExtractor`. */
    public static function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException("Identificador de columna/tabla inválido: '{$identifier}'.");
        }

        return '[' . $identifier . ']';
    }
}
