<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-757 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae PRESUPUESTOSCAB+PRESUPUESTOSLIN de
 * ICG (SQL Server, conexión `legacy_icg`) para el batch de Cotizaciones
 * (Illumination/migracion-icg-diseno.md §2/§4/§5).
 *
 * Sin filtro de "solo activos/vigentes" — a diferencia de `ProductoExtractor`
 * (`ARTICULOS.DESCATALOGADO='F'`), el alcance de este batch es extraer TODO el historial de
 * PRESUPUESTOSCAB (94,823 filas reales, §2) tal como está; ningún criterio de exclusión por
 * `IDESTADO` fue pedido — el ticket lo captura como un atributo más, no como filtro.
 *
 * GOTCHA DE TIPO SIN VERIFICAR CONTRA DATA REAL (§13.2 del diseño, mismo patrón que
 * `CLIENTES.BLOQUEADO` en `ClienteExtractor`) — `IDESTADO`/`SUPRESUPUESTO` no se confirmaron
 * todavía contra el SQL Server real de ICG. Se extraen como texto crudo sin interpretar
 * (`nullableString`, nunca casteados a int/bool) — decisión deliberadamente conservadora: ninguna
 * lógica de negocio depende hoy de su valor, así que guardar el tipo equivocado no rompe nada,
 * pero **confirmar el tipo real antes de dar por cerrada la entidad** por si conviene ajustar el
 * mapeo (ej. si `IDESTADO` resulta ser un código numérico, o `SUPRESUPUESTO` un NDBOOLEANF).
 *
 * `CODCLIENTE`/`CODARTICULO` — FKs "por convención" (⚠️, §2), sin constraint real declarado en
 * ICG — se extraen nullable, nunca se asume que siempre están poblados.
 *
 * PAGINADO DE ITEMS DESDE 2026-08-21 (hallazgo real de la primera Tarea 6 contra dev.atlanticerp.ai) —
 * un `extract()` original que traía las 715,530 filas de PRESUPUESTOSLIN de una sola vez
 * (`DB::select()` sin chunking) materializaba todo en memoria PHP antes de poder stagear una sola
 * fila. `dev.atlanticerp.ai` es un EC2 t3.small (2GB total, ~1.5GB ya usados por los otros 4
 * contenedores) — confirmado con `sudo dmesg -T | grep -i "killed process"` que el KERNEL mataba
 * el proceso por falta de memoria del HOST, no un error catcheable de PHP (`memory_limit=512M` no
 * protege contra esto — el límite real está un nivel más abajo, en la RAM física compartida). Ver
 * `Illumination/migracion-icg-diseno.md` §13.3 para el detalle completo. Fix: `extractItemsPage()`
 * pagina PRESUPUESTOSLIN con `OFFSET...FETCH` (requiere `ORDER BY`, ver `itemsPageQuery()`) —
 * `App\Modules\Legacy\Services\CotizacionStagingLoader::loadPaginated()` la llama en un loop,
 * stageando cada página antes de pedir la siguiente, así que nunca hay más de UNA página de items
 * en memoria a la vez. Las cabeceras (94,823 filas, ~8x el volumen de Clientes que ya corrió sin
 * problema) se siguen extrayendo completas de una — volumen mucho menor, no es la fuente del OOM.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `ClienteExtractor`/`ProductoExtractor`, a propósito, para que `mapCotizacionRow()`/
 * `mapItemRow()` sean testeables con arrays de filas fake, sin ninguna conexión real — ver
 * tests/Unit/Legacy/CotizacionExtractorTest.php): son `static`, puras, y `extractCotizaciones()`/
 * `extractItemsPage()` son los únicos métodos que tocan la red.
 */
final class CotizacionExtractor implements CotizacionExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** @return list<CotizacionRow> */
    public function extractCotizaciones(): array
    {
        $rows = DB::connection($this->connection)->select(self::cotizacionesQuery());

        return array_values(array_map(
            static fn (object $row): CotizacionRow => self::mapCotizacionRow((array) $row),
            $rows,
        ));
    }

    /** @return list<CotizacionItemRow> */
    public function extractItemsPage(int $offset, int $limit): array
    {
        $rows = DB::connection($this->connection)->select(self::itemsPageQuery(), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): CotizacionItemRow => self::mapItemRow((array) $row),
            $rows,
        ));
    }

    /**
     * SCRUM-842 — LEFT JOIN a PRESUPUESTOSCAMPOSLIBRES (PROYECTO/PROMOCION), misma llave natural de
     * 4 columnas que la cabecera. LEFT, nunca INNER: una cotización sin fila satélite igual se
     * extrae completa, `proyecto`/`promocion` quedan `null`.
     */
    public static function cotizacionesQuery(): string
    {
        return <<<'SQL'
            SELECT p.NUMSERIE, p.NUMPRESUPUESTO, p.N, p.VERSION, p.CODCLIENTE, p.CODVENDEDOR,
                   p.FECHA, p.TOTNETO, p.TOTBRUTO, p.IDESTADO, p.SUPRESUPUESTO,
                   cl.PROYECTO, cl.PROMOCION
            FROM PRESUPUESTOSCAB p
            LEFT JOIN PRESUPUESTOSCAMPOSLIBRES cl
                ON cl.NUMSERIE = p.NUMSERIE AND cl.NUMPRESUPUESTO = p.NUMPRESUPUESTO
                AND cl.N = p.N AND cl.VERSION = p.VERSION
            SQL;
    }

    /**
     * `OFFSET...FETCH` de SQL Server exige `ORDER BY` — se ordena por la llave natural compuesta
     * (mismo orden que usa Postgres para resolver `legacy_cotizacion_id`, aunque el orden en sí no
     * importa para la correctitud, solo necesita ser ESTABLE entre páginas — sin `ORDER BY`,
     * SQL Server no garantiza el mismo orden en cada `SELECT`, lo que podría saltear u repetir
     * filas entre una página y la siguiente).
     */
    public static function itemsPageQuery(): string
    {
        return <<<'SQL'
            SELECT l.NUMSERIE, l.NUMPRESUPUESTO, l.N, l.VERSION, l.CODARTICULO, l.REFERENCIA,
                   l.DESCRIPCION, l.UNIDADESTOTAL, l.PRECIO, l.DTO, l.TOTAL, l.COSTE
            FROM PRESUPUESTOSLIN l
            ORDER BY l.NUMSERIE, l.NUMPRESUPUESTO, l.N, l.VERSION
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `cotizacionesQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapCotizacionRow(array $row): CotizacionRow
    {
        return new CotizacionRow(
            // Llave natural — obligatoria (PK real de PRESUPUESTOSCAB, §2), nunca nula.
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumpresupuesto: (int) $row['NUMPRESUPUESTO'],
            icgN: (int) $row['N'],
            icgVersion: (int) $row['VERSION'],
            icgCodcliente: self::nullableInt($row['CODCLIENTE'] ?? null),
            fecha: self::nullableDateTime($row['FECHA'] ?? null),
            totalNeto: self::nullableFloat($row['TOTNETO'] ?? null),
            totalBruto: self::nullableFloat($row['TOTBRUTO'] ?? null),
            icgCodvendedor: self::nullableInt($row['CODVENDEDOR'] ?? null),
            idestado: self::nullableString($row['IDESTADO'] ?? null),
            supresupuesto: self::nullableString($row['SUPRESUPUESTO'] ?? null),
            proyecto: self::nullableString($row['PROYECTO'] ?? null),
            promocion: self::nullableString($row['PROMOCION'] ?? null),
        );
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `itemsQuery()`.
     *
     * @param array<string, mixed> $row
     */
    public static function mapItemRow(array $row): CotizacionItemRow
    {
        return new CotizacionItemRow(
            // Llave natural de la CABECERA (no de la línea, ver docblock de la clase/migración).
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumpresupuesto: (int) $row['NUMPRESUPUESTO'],
            icgN: (int) $row['N'],
            icgVersion: (int) $row['VERSION'],
            icgCodarticulo: self::nullableInt($row['CODARTICULO'] ?? null),
            referencia: self::nullableString($row['REFERENCIA'] ?? null),
            descripcion: self::nullableString($row['DESCRIPCION'] ?? null),
            cantidad: self::nullableFloat($row['UNIDADESTOTAL'] ?? null),
            precio: self::nullableFloat($row['PRECIO'] ?? null),
            descuento: self::nullableFloat($row['DTO'] ?? null),
            total: self::nullableFloat($row['TOTAL'] ?? null),
            costo: self::nullableFloat($row['COSTE'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * `FECHA` llega del driver `pdo_sqlsrv` como string ya formateado (`Y-m-d H:i:s[.u]`).
     * Defensivo: una fecha malformada nunca debe reventar todo el extracto — se descarta esa
     * columna puntual (`null`), el resto de la fila sigue mapeándose normalmente. Mismo patrón que
     * `ClienteExtractor::nullableDateTime()`.
     */
    private static function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
