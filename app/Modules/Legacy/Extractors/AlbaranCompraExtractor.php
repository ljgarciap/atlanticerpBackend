<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-840 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae ALBCOMPRACAB+ALBCOMPRALIN de ICG —
 * equivalente de compras a `AlbaranExtractor` (Albaranes de Venta, SCRUM-759). Mismo patrón exacto:
 * separación construcción-de-query/mapeo-de-filas/acto-de-conectar, items paginados vía
 * `extractItemsPage()` (`OFFSET...FETCH`, requiere `ORDER BY` estable entre páginas).
 *
 * Sin filtro de "solo activos" — se extrae todo el historial, mismo criterio que el resto del
 * módulo. `icg_n` se extrae como `(string)` desde el diseño inicial (gotcha ya confirmado en las 6
 * tablas hermanas, ver docblock de la migración de `legacy.albaranes_compra`).
 */
final class AlbaranCompraExtractor implements AlbaranCompraExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** @return list<AlbaranCompraRow> */
    public function extractAlbaranes(): array
    {
        $rows = DB::connection($this->connection)->select(self::albaranesQuery());

        return array_values(array_map(
            static fn (object $row): AlbaranCompraRow => self::mapAlbaranRow((array) $row),
            $rows,
        ));
    }

    /** @return list<AlbaranCompraItemRow> */
    public function extractItemsPage(int $offset, int $limit): array
    {
        $rows = DB::connection($this->connection)->select(self::itemsPageQuery(), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): AlbaranCompraItemRow => self::mapItemRow((array) $row),
            $rows,
        ));
    }

    public static function albaranesQuery(): string
    {
        return <<<'SQL'
            SELECT a.NUMSERIE, a.NUMALBARAN, a.N, a.CODPROVEEDOR, a.FECHAALBARAN, a.TOTALNETO,
                   a.FACTURADO, a.NUMSERIEFAC, a.NUMFAC
            FROM ALBCOMPRACAB a
            SQL;
    }

    /** @see AlbaranExtractor::itemsPageQuery() mismo motivo del ORDER BY obligatorio */
    public static function itemsPageQuery(): string
    {
        return <<<'SQL'
            SELECT l.NUMSERIE, l.NUMALBARAN, l.N, l.CODARTICULO, l.REFERENCIA,
                   l.DESCRIPCION, l.UNIDADESTOTAL, l.PRECIO, l.TOTAL
            FROM ALBCOMPRALIN l
            ORDER BY l.NUMSERIE, l.NUMALBARAN, l.N
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;
    }

    /** @param array<string, mixed> $row */
    public static function mapAlbaranRow(array $row): AlbaranCompraRow
    {
        return new AlbaranCompraRow(
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumalbaran: (int) $row['NUMALBARAN'],
            icgN: (string) $row['N'],
            icgCodproveedor: self::nullableInt($row['CODPROVEEDOR'] ?? null),
            fecha: self::nullableDateTime($row['FECHAALBARAN'] ?? null),
            totalneto: self::nullableFloat($row['TOTALNETO'] ?? null),
            // ALBCOMPRACAB.FACTURADO — mismo NDBOOLEANF 'T'/'F' confirmado en ALBVENTACAB.FACTURADO
            // (ver docblock de AlbaranExtractor); no re-confirmado directamente contra ICG real
            // para esta tabla puntual, se sigue el mismo patrón fuerte de las tablas hermanas.
            facturado: ($row['FACTURADO'] ?? null) === 'T',
            icgNumseriefac: self::nullableString($row['NUMSERIEFAC'] ?? null),
            icgNumfac: self::nullableInt($row['NUMFAC'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    public static function mapItemRow(array $row): AlbaranCompraItemRow
    {
        return new AlbaranCompraItemRow(
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
