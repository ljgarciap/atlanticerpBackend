<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-760 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae FACTURASVENTA de ICG (SQL Server,
 * conexión `legacy_icg`) para el batch de Facturas (Illumination/migracion-icg-diseno.md §2/§4/§5).
 *
 * Sin filtro de "solo activas/vigentes" — mismo criterio que `PedidoExtractor`/`AlbaranExtractor`:
 * el alcance de este batch es extraer TODO el historial de FACTURASVENTA (60,215 filas §2) tal
 * como está.
 *
 * Sin tabla de líneas — a diferencia de `PedidoExtractor`/`AlbaranExtractor` (header+líneas),
 * FACTURASVENTA no tiene una tabla hermana de líneas documentada en el discovery (§2) — mismo
 * alcance de una sola tabla que `ProveedorExtractor`.
 *
 * `N` — TEXTO, no entero (ver GOTCHA en el docblock de la migración de `legacy.facturas` — no
 * confirmado directamente para esta tabla, declarado texto por el mismo patrón fuerte de las 6
 * tablas hermanas ya auditadas el 2026-08-17).
 *
 * `CODCLIENTE`/`CODVENDEDOR` — FKs "por convención" (⚠️, §2), sin constraint real declarado en
 * ICG — se extraen nullable, nunca se asume que siempre están poblados.
 *
 * `SUFACTURA` — nombre engañoso, no es booleano (ver docblock de la migración) — se extrae como
 * texto crudo sin interpretar, mismo criterio que `SUPRESUPUESTO` en `CotizacionExtractor`.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `AlbaranExtractor`, a propósito, para que `mapFacturaRow()` sea testeable con arrays de filas
 * fake, sin ninguna conexión real — ver tests/Unit/Legacy/FacturaExtractorTest.php): es `static`,
 * pura, y `extract()` es el único método que toca la red.
 *
 * SCRUM-841 — `facturasQuery()` agrega un LEFT JOIN a `FACTURASVENTACAMPOSLIBRES` (Facturación
 * Electrónica: QR, número de autorización, estado ante la DGI) — ver docblock del método.
 */
final class FacturaExtractor implements FacturaExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): ExtractedFacturas
    {
        $facturaRows = DB::connection($this->connection)->select(self::facturasQuery());

        $facturas = array_values(array_map(
            static fn (object $row): FacturaRow => self::mapFacturaRow((array) $row),
            $facturaRows,
        ));

        return new ExtractedFacturas($facturas);
    }

    /**
     * LEFT JOIN a FACTURASVENTACAMPOSLIBRES (SCRUM-841, Facturación Electrónica) — misma llave
     * natural que la cabecera (NUMSERIE+NUMFACTURA+N). LEFT, nunca INNER: una factura sin fila en
     * la tabla satélite (ej. anterior a que el cliente adoptara FE) igual se extrae completa, los
     * campos `fe_*` quedan `null`.
     */
    public static function facturasQuery(): string
    {
        return <<<'SQL'
            SELECT f.NUMSERIE, f.NUMFACTURA, f.N, f.CODCLIENTE, f.CODVENDEDOR, f.FECHA,
                   f.TOTALNETO, f.TOTALIMPUESTOS, f.TOTALBRUTO, f.SUFACTURA,
                   cl.FEQR, cl.FEAUTHNUMBER, cl.FESERIE, cl.FENUMERO,
                   cl.FEFECHAEMISON, cl.FEFECHARECEPCION, cl.FEERROR, cl.FEESTADO
            FROM FACTURASVENTA f
            LEFT JOIN FACTURASVENTACAMPOSLIBRES cl
                ON cl.NUMSERIE = f.NUMSERIE AND cl.NUMFACTURA = f.NUMFACTURA AND cl.N = f.N
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `facturasQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapFacturaRow(array $row): FacturaRow
    {
        return new FacturaRow(
            // Llave natural — obligatoria (PK real de FACTURASVENTA, §2: 3 columnas), nunca nula.
            // icgN es TEXTO, no entero (ver docblock de la clase).
            icgNumserie: (string) $row['NUMSERIE'],
            icgNumfactura: (int) $row['NUMFACTURA'],
            icgN: (string) $row['N'],
            icgCodcliente: self::nullableInt($row['CODCLIENTE'] ?? null),
            icgCodvendedor: self::nullableInt($row['CODVENDEDOR'] ?? null),
            fecha: self::nullableDateTime($row['FECHA'] ?? null),
            totalNeto: self::nullableFloat($row['TOTALNETO'] ?? null),
            totalImpuestos: self::nullableFloat($row['TOTALIMPUESTOS'] ?? null),
            totalBruto: self::nullableFloat($row['TOTALBRUTO'] ?? null),
            sufactura: self::nullableString($row['SUFACTURA'] ?? null),
            feQr: self::nullableString($row['FEQR'] ?? null),
            feAuthNumber: self::nullableString($row['FEAUTHNUMBER'] ?? null),
            feSerie: self::nullableString($row['FESERIE'] ?? null),
            feNumero: self::nullableString($row['FENUMERO'] ?? null),
            feFechaEmision: self::nullableString($row['FEFECHAEMISON'] ?? null),
            feFechaRecepcion: self::nullableString($row['FEFECHARECEPCION'] ?? null),
            feError: self::nullableString($row['FEERROR'] ?? null),
            feEstado: self::nullableString($row['FEESTADO'] ?? null),
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
     * columna puntual (`null`), el resto de la fila sigue mapeándose normalmente.
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
