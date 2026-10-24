<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Support\PriceTypeMap;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-750 (épica SCRUM-749 — Migración ICG→AtlanticERP), piloto de Catálogo
 * (Illumination/migracion-icg-diseno.md §6.1). Extrae ARTICULOS+PRECIOSVENTA+PRECIOSCOMPRA de
 * ICG (SQL Server, conexión `legacy_icg`) para el batch de Productos.
 *
 * GOTCHA CRÍTICO — tipo `NDBOOLEANF` de ICG, inconsistente ENTRE tablas (confirmado 2026-08-06,
 * ver §9 riesgo #4 del diseño): `ARTICULOS.DESCATALOGADO` es TEXTO: los valores REALES medidos en
 * ICG son 'T' (descatalogado) y 'F' (activo); 'V' se acepta solo por compatibilidad con el supuesto original
 * (SCRUM-1136 F5). Nunca comparar contra 0/1. `PRECIOSVENTA.DESCATALOGADO` en cambio SÍ es un `bit` real (0/1). Cada método de
 * mapeo de esta clase interpreta el formato correcto para SU tabla — no asumir uniformidad.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (a propósito, para que
 * `mapProductoRow()`/`mapPrecioRow()`/`mapCostoRow()` sean testeables con arrays de filas fake,
 * sin ninguna conexión real — ver tests/Unit/Legacy/ProductoExtractorTest.php): son `static`,
 * puras, y `extract()` es el único método que toca la red.
 *
 * DECISIÓN DE DISEÑO — `familia_nombre`/`marca_nombre` NO resuelven el nombre real contra las
 * tablas `FAMILIAS`/`MARCA` de ICG en este batch: la PK compuesta de `FAMILIAS`
 * (`NUMDPTO+NUMSECCION+NUMFAMILIA`) nunca se confirmó contra `ARTICULOS.FAMILIA`/`SUBFAMILIA` con
 * data real (el propio SELECT de referencia del ticket tampoco hace ese join). En vez de inventar
 * un join no confirmado, se persiste el CÓDIGO crudo de ICG en esas columnas — queda documentado
 * acá y en el docblock de la migración de `legacy.productos` como deuda a resolver cuando se
 * confirme la relación real.
 *
 * DECISIÓN DE DISEÑO — tanto `preciosQuery()` como `costosQuery()` hacen INNER JOIN a `ARTICULOS`
 * filtrado por `DESCATALOGADO = 'F'`, aunque el filtro de "solo activos" ya vive en
 * `articulosQuery()`: `legacy.producto_precios`/`legacy.producto_costos` tienen FK a
 * `legacy.productos.icg_codarticulo`, así que sin este join una fila de precio/costo de un
 * artículo descatalogado violaría la FK al momento de stagear (`legacy.productos` nunca lo tiene).
 *
 * DECISIÓN DE DISEÑO — `preciosQuery()` NO filtra `IDTARIFAV` ni `DESCATALOGADO` en SQL: la
 * tarifa 4 (D.BAHIA) y cualquier fila con `DESCATALOGADO=1` llegan a `mapPrecioRow()`, que las
 * excluye en PHP (`null`). Es defensa en profundidad — no confiar ciegamente en que "se
 * auto-excluye sola" del lado de ICG sin verificarlo también acá.
 */
final class ProductoExtractor implements ProductoExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): ExtractedProductos
    {
        $articuloRows = DB::connection($this->connection)->select(self::articulosQuery());
        $precioRows   = DB::connection($this->connection)->select(self::preciosQuery());
        $costoRows    = DB::connection($this->connection)->select(self::costosQuery());

        $productos = array_values(array_map(
            static fn (object $row): ProductoRow => self::mapProductoRow((array) $row),
            $articuloRows,
        ));

        $precios = [];
        foreach ($precioRows as $row) {
            $mapped = self::mapPrecioRow((array) $row);
            if ($mapped !== null) {
                $precios[] = $mapped;
            }
        }

        $costos = array_values(array_map(
            static fn (object $row): ProductoCostoRow => self::mapCostoRow((array) $row),
            $costoRows,
        ));

        return new ExtractedProductos($productos, $precios, $costos);
    }

    /**
     * SCRUM-921 — `LEFT JOIN` (no INNER) a `STOCKS` filtrado a `CODALMACEN='AL4'` (Bodega
     * Central — única fuente de stock vendible según el negocio, ver docblock de
     * `App\Modules\Legacy\Services\CatalogProductLoader` para el uso). Es 1:1 por `CODARTICULO`:
     * `TALLA`/`COLOR` siempre son `'.'` para Illumination (esas variantes son de retail de ropa,
     * no aplican acá), así que junto con `CODALMACEN='AL4'` fijo no hay riesgo de fila duplicada.
     * `LEFT JOIN` a propósito — un artículo activo sin ninguna fila en AL4 (nunca tuvo movimiento
     * ahí) debe seguir llegando a `articulosQuery()`, con `STOCK_AL4` en `NULL`.
     */
    public static function articulosQuery(): string
    {
        return <<<'SQL'
            SELECT a.CODARTICULO, a.DESCRIPCION, a.MARCA, a.FAMILIA, a.SUBFAMILIA,
                   a.REFPROVEEDOR, a.DESCATALOGADO, s.STOCK AS STOCK_AL4
            FROM ARTICULOS a
            LEFT JOIN STOCKS s ON s.CODARTICULO = a.CODARTICULO
                               AND s.CODALMACEN = 'AL4' AND s.TALLA = '.' AND s.COLOR = '.'
            WHERE a.DESCATALOGADO = 'F'
            SQL;
    }

    public static function preciosQuery(): string
    {
        return <<<'SQL'
            SELECT p.CODARTICULO, p.IDTARIFAV, p.PNETO, p.PBRUTO, p.DTO, p.DESCATALOGADO
            FROM PRECIOSVENTA p
            INNER JOIN ARTICULOS a ON a.CODARTICULO = p.CODARTICULO
            WHERE a.DESCATALOGADO = 'F'
              AND p.TALLA = '.' AND p.COLOR = '.' AND p.CODFORMATO = 0
            SQL;
    }

    public static function costosQuery(): string
    {
        return <<<'SQL'
            SELECT c.CODPROVEEDOR, c.CODARTICULO, c.PNETO
            FROM PRECIOSCOMPRA c
            INNER JOIN ARTICULOS a ON a.CODARTICULO = c.CODARTICULO
            WHERE a.DESCATALOGADO = 'F'
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `articulosQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapProductoRow(array $row): ProductoRow
    {
        $familia    = self::nullableString($row['FAMILIA'] ?? null);
        $subfamilia = self::nullableString($row['SUBFAMILIA'] ?? null);
        $marca      = self::nullableString($row['MARCA'] ?? null);

        return new ProductoRow(
            icgCodarticulo: (int) $row['CODARTICULO'],
            descripcion: (string) $row['DESCRIPCION'],
            // Código crudo, no nombre resuelto — ver docblock de la clase.
            familiaNombre: self::joinNullable($familia, $subfamilia),
            marcaNombre: $marca,
            refProveedor: self::nullableString($row['REFPROVEEDOR'] ?? null),
            // ARTICULOS.DESCATALOGADO es NDBOOLEANF de texto. Valores REALES medidos en ICG (2026-10-15, SCRUM-1136 F5):
            // 'T' = descatalogado, 'F' = activo. 'V' se conserva por compatibilidad con el supuesto original del
            // código (nunca observado en datos reales). El flujo de activos solo extrae 'F', así que este cambio no lo altera.
            descatalogado: in_array($row['DESCATALOGADO'] ?? null, ['T', 'V'], true),
            stockAl4: self::nullableFloat($row['STOCK_AL4'] ?? null),
        );
    }

    /**
     * Pura — sin DB. Devuelve `null` si la tarifa no mapea a un `price_type` de AtlanticERP (tarifa 4,
     * D.BAHIA) o si la fila viene marcada `DESCATALOGADO=1` — en ambos casos la fila se excluye
     * del staging (`legacy.producto_precios` nunca la tiene).
     *
     * @param array<string, mixed> $row
     */
    public static function mapPrecioRow(array $row): ?ProductoPrecioRow
    {
        $idtarifav = (int) $row['IDTARIFAV'];

        // PRECIOSVENTA.DESCATALOGADO SÍ es bit real (0/1) — distinto de ARTICULOS.
        $descatalogadoFila = self::truthyBit($row['DESCATALOGADO'] ?? 0);

        if (! PriceTypeMap::isMapped($idtarifav) || $descatalogadoFila) {
            return null;
        }

        return new ProductoPrecioRow(
            icgCodarticulo: (int) $row['CODARTICULO'],
            idtarifav: $idtarifav,
            precioNeto: (float) $row['PNETO'],
            precioBruto: self::nullableFloat($row['PBRUTO'] ?? null),
            descuento: self::nullableFloat($row['DTO'] ?? null),
            descatalogadoFila: $descatalogadoFila,
        );
    }

    /**
     * Pura — sin DB. Sin filtrar, captura toda fila de costo/proveedor (ver docblock de la
     * clase).
     *
     * @param array<string, mixed> $row
     */
    public static function mapCostoRow(array $row): ProductoCostoRow
    {
        return new ProductoCostoRow(
            icgCodarticulo: (int) $row['CODARTICULO'],
            codproveedor: (int) $row['CODPROVEEDOR'],
            costoNeto: (float) $row['PNETO'],
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

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function joinNullable(?string $a, ?string $b): ?string
    {
        $parts = array_filter([$a, $b], static fn (?string $part): bool => $part !== null);

        return $parts === [] ? null : implode('/', $parts);
    }

    private static function truthyBit(mixed $value): bool
    {
        return ((int) $value) === 1;
    }
}
