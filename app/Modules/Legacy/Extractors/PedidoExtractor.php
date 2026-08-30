<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-758 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae PEDVENTACAB+PEDVENTALIN de ICG (SQL
 * Server, conexión `legacy_icg`) para el batch de Pedidos (Illumination/migracion-icg-diseno.md
 * §2/§4/§5).
 *
 * Sin filtro de "solo activos/vigentes" — mismo criterio que `CotizacionExtractor`: el alcance de
 * este batch es extraer TODO el historial de PEDVENTACAB (43,778 filas reales, §2) tal como está;
 * ningún criterio de exclusión por `IDESTADO` fue pedido.
 *
 * GOTCHA DE TIPO SIN VERIFICAR CONTRA DATA REAL (§13.2 del diseño, mismo patrón que
 * `CotizacionExtractor::idestado`) — `IDESTADO` no se confirmó todavía contra el SQL Server real
 * de ICG (la corrida real está fuera de alcance de este batch, ver docblock de
 * `LegacySyncPedidosCommand`). Se extrae como texto crudo sin interpretar (`nullableString`, nunca
 * casteado a int/bool) — decisión deliberadamente conservadora: ninguna lógica de negocio depende
 * hoy de su valor, así que guardar el tipo equivocado no rompe nada, pero **confirmar el tipo real
 * antes de la primera corrida real** (equivalente a la Tarea 6 de SCRUM-750/755/757).
 *
 * `CODCLIENTE`/`CODVENDEDOR`/`CODARTICULO` — FKs "por convención" (⚠️, §2), sin constraint real
 * declarado en ICG — se extraen nullable, nunca se asume que siempre están poblados.
 * `SERIEALBARAN`/`NUMEROALBARAN` — mismo criterio, link crudo a ALBVENTACAB sin resolver en este
 * batch (ver docblock de la migración de `legacy.pedidos`).
 *
 * PAGINADO DE ITEMS DESDE 2026-08-21 — fix preventivo, mismo patrón que
 * `App\Modules\Legacy\Extractors\CotizacionExtractor` tras el OOM real de SCRUM-757 (ver su
 * docblock para el detalle completo del hallazgo). `extractItemsPage()` pagina PEDVENTALIN con
 * `OFFSET...FETCH` — `App\Modules\Legacy\Services\PedidoStagingLoader::loadPaginated()` la llama en
 * un loop, stageando cada página antes de pedir la siguiente.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `CotizacionExtractor`, a propósito, para que `mapPedidoRow()`/`mapItemRow()` sean testeables con
 * arrays de filas fake, sin ninguna conexión real — ver tests/Unit/Legacy/PedidoExtractorTest.php):
 * son `static`, puras, y `extractPedidos()`/`extractItemsPage()` son los únicos métodos que tocan
 * la red.
 */
final class PedidoExtractor implements PedidoExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** @return list<PedidoRow> */
    public function extractPedidos(): array
    {
        $rows = DB::connection($this->connection)->select(self::pedidosQuery());

        return array_values(array_map(
            static fn (object $row): PedidoRow => self::mapPedidoRow((array) $row),
            $rows,
        ));
    }

    /** @return list<PedidoItemRow> */
    public function extractItemsPage(int $offset, int $limit): array
    {
        $rows = DB::connection($this->connection)->select(self::itemsPageQuery(), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): PedidoItemRow => self::mapItemRow((array) $row),
            $rows,
        ));
    }

    public static function pedidosQuery(): string
    {
        return <<<'SQL'
            SELECT p.NUMSERIE, p.NUMPEDIDO, p.N, p.CODCLIENTE, p.CODVENDEDOR,
                   p.FECHAPEDIDO, p.FECHAENTREGA, p.TOTNETO, p.IDESTADO,
                   p.SERIEALBARAN, p.NUMEROALBARAN
            FROM PEDVENTACAB p
            SQL;
    }

    /**
     * `OFFSET...FETCH` de SQL Server exige `ORDER BY` — ver mismo comentario en
     * `CotizacionExtractor::itemsPageQuery()` sobre por qué es necesario para estabilidad entre
     * páginas, no solo un requisito sintáctico.
     */
    public static function itemsPageQuery(): string
    {
        return <<<'SQL'
            SELECT l.NUMSERIE, l.NUMPEDIDO, l.N, l.CODARTICULO, l.REFERENCIA,
                   l.DESCRIPCION, l.UNIDADESTOTAL, l.PRECIO, l.TOTAL
            FROM PEDVENTALIN l
            ORDER BY l.NUMSERIE, l.NUMPEDIDO, l.N
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `pedidosQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapPedidoRow(array $row): PedidoRow
    {
        return new PedidoRow(
            // Llave natural — obligatoria (PK real de PEDVENTACAB, §2: 3 columnas, sin VERSION),
            // nunca nula.
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumpedido: (int) $row['NUMPEDIDO'],
            icgN: (int) $row['N'],
            icgCodcliente: self::nullableInt($row['CODCLIENTE'] ?? null),
            fechapedido: self::nullableDateTime($row['FECHAPEDIDO'] ?? null),
            fechaentrega: self::nullableDateTime($row['FECHAENTREGA'] ?? null),
            totneto: self::nullableFloat($row['TOTNETO'] ?? null),
            icgCodvendedor: self::nullableInt($row['CODVENDEDOR'] ?? null),
            idestado: self::nullableString($row['IDESTADO'] ?? null),
            icgSeriealbaran: self::nullableString($row['SERIEALBARAN'] ?? null),
            icgNumeroalbaran: self::nullableInt($row['NUMEROALBARAN'] ?? null),
        );
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `itemsQuery()`.
     *
     * @param array<string, mixed> $row
     */
    public static function mapItemRow(array $row): PedidoItemRow
    {
        return new PedidoItemRow(
            // Llave natural de la CABECERA (no de la línea, ver docblock de la clase/migración).
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumpedido: (int) $row['NUMPEDIDO'],
            icgN: (int) $row['N'],
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
     * `FECHAPEDIDO`/`FECHAENTREGA` llegan del driver `pdo_sqlsrv` como string ya formateado
     * (`Y-m-d H:i:s[.u]`). Defensivo: una fecha malformada nunca debe reventar todo el extracto —
     * se descarta esa columna puntual (`null`), el resto de la fila sigue mapeándose normalmente.
     * Mismo patrón que `CotizacionExtractor::nullableDateTime()`.
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
