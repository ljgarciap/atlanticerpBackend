<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Data\PagoRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-761 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae TESORERIA de ICG (SQL Server, conexión
 * `legacy_icg`) para el batch de Pagos (Illumination/migracion-icg-diseno.md §2/§4/§5).
 *
 * Sin filtro — se extrae todo TESORERIA (144,194 filas §2) tal como está, sin criterio de
 * exclusión ni de estado.
 *
 * Sin Matcher, sin tabla de líneas — mismo alcance de una sola tabla que `ProveedorExtractor`
 * (ver docblock de `App\Modules\Legacy\Data\ExtractedPagos` — TESORERIA no referencia cliente).
 *
 * Llave natural REAL de 6 columnas (`ORIGEN`+`TIPODOCUMENTO`+`SERIE`+`NUMERO`+`N`+`POSICION`, §2)
 * — ver docblock de la migración de `legacy.pagos` para el porqué de las 6 (no las 4 del sketch
 * abreviado de §4).
 *
 * `N` — TEXTO, no entero (mismo criterio conservador que `AlbaranExtractor`/`FacturaExtractor`,
 * ver docblock de la migración). `ORIGEN`/`TIPODOCUMENTO`/`SERIE` — tipo real sin confirmar,
 * declarados texto por ser códigos cortos (mismo criterio defensivo del resto del módulo).
 *
 * `ESTADO` — mismo GOTCHA que las columnas `IDESTADO`-style (§13.2 del diseño) — se extrae como
 * texto crudo sin interpretar, tipo real sin confirmar.
 *
 * PAGINADO DESDE 2026-08-27 (hallazgo real, ver docblock de `PagoExtractorContract`) — un
 * `extract()` original que traía las 145,250 filas de TESORERIA de una sola vez (`DB::select()`
 * sin chunking) confirmó 2 OOM kills reales (`sudo dmesg -T`) contra dev.atlanticerp.ai, mismo síntoma
 * que Cotizaciones/Albaranes pese a ser tabla única. Fix: `extractPage()` pagina TESORERIA con
 * `OFFSET...FETCH` (requiere `ORDER BY`, ver `pagosPageQuery()`) —
 * `App\Modules\Legacy\Services\PagoStagingLoader::loadPaginated()` la llama en un loop, stageando
 * cada página antes de pedir la siguiente. A diferencia de Albaranes/Cotizaciones, acá TODA la
 * extracción es paginada — no hay una cabecera separada de menor volumen.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `AlbaranExtractor`/`CotizacionExtractor`, a propósito, para que `mapPagoRow()` sea testeable con
 * arrays de filas fake, sin ninguna conexión real — ver tests/Unit/Legacy/PagoExtractorTest.php):
 * es `static`, pura, y `extractPage()` es el único método que toca la red.
 */
final class PagoExtractor implements PagoExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    /** @return list<PagoRow> */
    public function extractPage(int $offset, int $limit): array
    {
        $rows = DB::connection($this->connection)->select(self::pagosPageQuery(), [$offset, $limit]);

        return array_values(array_map(
            static fn (object $row): PagoRow => self::mapPagoRow((array) $row),
            $rows,
        ));
    }

    /**
     * `OFFSET...FETCH` de SQL Server exige `ORDER BY` — acá se ordena por la llave natural REAL
     * completa de 6 columnas (§2 del diseño), así que a diferencia de
     * `AlbaranExtractor::itemsPageQuery()` (que solo ordena por la llave de la cabecera, con
     * múltiples líneas por cabecera) este orden es único fila por fila, no solo estable entre
     * páginas.
     */
    public static function pagosPageQuery(): string
    {
        return <<<'SQL'
            SELECT t.ORIGEN, t.TIPODOCUMENTO, t.SERIE, t.NUMERO, t.N, t.POSICION,
                   t.FECHADOCUMENTO, t.FECHAVENCIMIENTO, t.IMPORTE, t.CODFORMAPAGO, t.ESTADO,
                   t.FECHASALDADO
            FROM TESORERIA t
            ORDER BY t.ORIGEN, t.TIPODOCUMENTO, t.SERIE, t.NUMERO, t.N, t.POSICION
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `pagosQuery()` (claves de columna
     * de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapPagoRow(array $row): PagoRow
    {
        return new PagoRow(
            // Llave natural — obligatoria (PK real de TESORERIA, §2: 6 columnas), nunca nula.
            // icgN es TEXTO, no entero (ver docblock de la clase).
            icgOrigen: (string) $row['ORIGEN'],
            icgTipodocumento: (string) $row['TIPODOCUMENTO'],
            icgSerie: (string) $row['SERIE'],
            icgNumero: (int) $row['NUMERO'],
            icgN: (string) $row['N'],
            icgPosicion: (int) $row['POSICION'],
            fechaDocumento: self::nullableDateTime($row['FECHADOCUMENTO'] ?? null),
            fechaVencimiento: self::nullableDateTime($row['FECHAVENCIMIENTO'] ?? null),
            importe: self::nullableFloat($row['IMPORTE'] ?? null),
            icgCodformapago: self::nullableInt($row['CODFORMAPAGO'] ?? null),
            estado: self::nullableString($row['ESTADO'] ?? null),
            fechaSaldado: self::nullableDateTime($row['FECHASALDADO'] ?? null),
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
     * `FECHADOCUMENTO`/`FECHAVENCIMIENTO`/`FECHASALDADO` llegan del driver `pdo_sqlsrv` como
     * string ya formateado (`Y-m-d H:i:s[.u]`). Defensivo: una fecha malformada nunca debe
     * reventar todo el extracto — se descarta esa columna puntual (`null`), el resto de la fila
     * sigue mapeándose normalmente.
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
