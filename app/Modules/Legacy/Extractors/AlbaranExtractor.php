<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-759 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae ALBVENTACAB+ALBVENTALIN de ICG (SQL
 * Server, conexión `legacy_icg`) para el batch de Albaranes (Illumination/migracion-icg-diseno.md
 * §2/§4/§5).
 *
 * Sin filtro de "solo activos/vigentes" — mismo criterio que `PedidoExtractor`/`CotizacionExtractor`:
 * el alcance de este batch es extraer TODO el historial de ALBVENTACAB (60,985 filas reales
 * confirmadas 2026-08-17, más que las 60,817 originales del discovery — ICG es un sistema vivo)
 * tal como está; ningún criterio de exclusión fue pedido.
 *
 * `N` — **TEXTO, no entero** (§13.2 del diseño, confirmado 2026-08-17 contra ICG real: `nchar(1)`,
 * valor constante `'B'`). A diferencia de `PedidoExtractor`/`CotizacionExtractor` (ya desplegados
 * con el casteo `(int)` equivocado antes de este hallazgo), acá se extrae con `(string)` desde el
 * diseño inicial — ver `AlbaranRow::$icgN`.
 *
 * `FACTURADO` — NDBOOLEANF confirmado 2026-08-17 contra ICG real: `nchar(1)`, `'T'`/`'F'` (60,690
 * `'T'` / 295 `'F'`), mismo literal que `CLIENTES.BLOQUEADO` — NO el mismo que
 * `ARTICULOS.DESCATALOGADO` (`'V'`). Mismo patrón de mapeo que `ClienteExtractor::bloqueado`.
 *
 * `CODCLIENTE`/`CODVENDEDOR`/`CODARTICULO` — FKs "por convención" (⚠️, §2) para vendedor/artículo,
 * sin constraint real declarado en ICG — se extraen nullable, nunca se asume que siempre están
 * poblados. `CODCLIENTE` es la excepción: ALBVENTACAB.CODCLIENTE→CLIENTES SÍ declara FK real (§2:
 * "✅ declarada"), pero igual se extrae nullable acá (defensivo, el matching real lo hace
 * `AlbaranMatcher` contra `legacy.clientes` staged por separado, no contra CLIENTES directo).
 * `NUMSERIEFAC`/`NUMFAC` — mismo criterio que `icg_seriealbaran`/`icg_numeroalbaran` en
 * `PedidoExtractor`: link crudo a FACTURASVENTA sin resolver en este batch (ver docblock de la
 * migración de `legacy.albaranes`).
 *
 * PAGINADO DE ITEMS DESDE 2026-08-27 (mismo hallazgo real que `CotizacionExtractor` el 2026-08-21
 * — nunca se portó el fix acá antes de esta corrida) — un `extract()` original que traía las
 * ~199,766 filas de ALBVENTALIN de una sola vez (`DB::select()` sin chunking) materializaba todo en
 * memoria PHP antes de poder stagear una sola fila, sobre el mismo host t3.small (2GB, ~1.5GB ya
 * usados por los otros 4 contenedores) que ya había mostrado el OOM real con Cotizaciones. Fix:
 * `extractItemsPage()` pagina ALBVENTALIN con `OFFSET...FETCH` (requiere `ORDER BY`, ver
 * `itemsPageQuery()`) — `App\Modules\Legacy\Services\AlbaranStagingLoader::loadPaginated()` la
 * llama en un loop, stageando cada página antes de pedir la siguiente. Las cabeceras (~60,985
 * filas, volumen mucho menor) se siguen extrayendo completas de una vía `extractAlbaranes()`.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `PedidoExtractor`/`CotizacionExtractor`, a propósito, para que `mapAlbaranRow()`/`mapItemRow()`
 * sean testeables con arrays de filas fake, sin ninguna conexión real — ver
 * tests/Unit/Legacy/AlbaranExtractorTest.php): son `static`, puras, y `extractAlbaranes()`/
 * `extractItemsPage()` son los únicos métodos que tocan la red.
 */
final class AlbaranExtractor implements AlbaranExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** @return list<AlbaranRow> */
    public function extractAlbaranes(): array
    {
        $rows = DB::connection($this->connection)->select(self::albaranesQuery());

        return array_values(array_map(
            static fn (object $row): AlbaranRow => self::mapAlbaranRow((array) $row),
            $rows,
        ));
    }

    /** @return list<AlbaranItemRow> */
    public function extractItemsPage(int $offset, int $limit): array
    {
        $rows = DB::connection($this->connection)->select(self::itemsPageQuery(), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): AlbaranItemRow => self::mapItemRow((array) $row),
            $rows,
        ));
    }

    /**
     * SCRUM-842 — LEFT JOIN a ALBVENTACAMPOSLIBRES (OBSERVACIONES), misma llave natural que la
     * cabecera. LEFT, nunca INNER: un albarán sin fila satélite igual se extrae completo,
     * `observaciones` queda `null`.
     */
    public static function albaranesQuery(): string
    {
        return <<<'SQL'
            SELECT a.NUMSERIE, a.NUMALBARAN, a.N, a.CODCLIENTE, a.FECHA, a.TOTALNETO,
                   a.FACTURADO, a.CODVENDEDOR, a.NUMSERIEFAC, a.NUMFAC, cl.OBSERVACIONES
            FROM ALBVENTACAB a
            LEFT JOIN ALBVENTACAMPOSLIBRES cl
                ON cl.NUMSERIE = a.NUMSERIE AND cl.NUMALBARAN = a.NUMALBARAN AND cl.N = a.N
            SQL;
    }

    /**
     * `OFFSET...FETCH` de SQL Server exige `ORDER BY` — se ordena por la llave natural de la
     * cabecera (mismo criterio que `CotizacionExtractor::itemsPageQuery()`; ALBVENTALIN no declara
     * llave propia, ver docblock de la migración de `legacy.albaran_items`) — solo necesita ser
     * ESTABLE entre páginas, no única por línea: sin `ORDER BY`, SQL Server no garantiza el mismo
     * orden en cada `SELECT`, lo que podría saltear o repetir filas entre una página y la siguiente.
     */
    public static function itemsPageQuery(): string
    {
        return <<<'SQL'
            SELECT l.NUMSERIE, l.NUMALBARAN, l.N, l.CODARTICULO, l.REFERENCIA,
                   l.DESCRIPCION, l.UNIDADESTOTAL, l.PRECIO, l.TOTAL
            FROM ALBVENTALIN l
            ORDER BY l.NUMSERIE, l.NUMALBARAN, l.N
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `albaranesQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapAlbaranRow(array $row): AlbaranRow
    {
        return new AlbaranRow(
            // Llave natural — obligatoria (PK real de ALBVENTACAB, §2: 3 columnas, sin VERSION),
            // nunca nula. icgN es TEXTO, no entero (ver docblock de la clase).
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumalbaran: (int) $row['NUMALBARAN'],
            icgN: (string) $row['N'],
            icgCodcliente: self::nullableInt($row['CODCLIENTE'] ?? null),
            fecha: self::nullableDateTime($row['FECHA'] ?? null),
            totalneto: self::nullableFloat($row['TOTALNETO'] ?? null),
            // ALBVENTACAB.FACTURADO — 'T' = true (confirmado contra ICG real, ver docblock de la
            // clase). Nunca 'V' pese a ser el mismo literal que ARTICULOS.DESCATALOGADO.
            facturado: ($row['FACTURADO'] ?? null) === 'T',
            icgCodvendedor: self::nullableInt($row['CODVENDEDOR'] ?? null),
            icgNumseriefac: self::nullableString($row['NUMSERIEFAC'] ?? null),
            icgNumfac: self::nullableInt($row['NUMFAC'] ?? null),
            observaciones: self::nullableString($row['OBSERVACIONES'] ?? null),
        );
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `itemsQuery()`.
     *
     * @param array<string, mixed> $row
     */
    public static function mapItemRow(array $row): AlbaranItemRow
    {
        return new AlbaranItemRow(
            // Llave natural de la CABECERA (no de la línea, ver docblock de la clase/migración).
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumalbaran: (int) $row['NUMALBARAN'],
            icgN: (string) $row['N'],
            icgCodarticulo: self::nullableInt($row['CODARTICULO'] ?? null),
            referencia: self::nullableString($row['REFERENCIA'] ?? null),
            descripcion: self::nullableString($row['DESCRIPCION'] ?? null),
            cantidad: self::nullableFloat($row['UNIDADESTOTAL'] ?? null),
            precio: self::nullableFloat($row['PRECIO'] ?? null),
            total: self::nullableFloat($row['TOTAL'] ?? null),
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
     * `PedidoExtractor::nullableDateTime()`.
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
