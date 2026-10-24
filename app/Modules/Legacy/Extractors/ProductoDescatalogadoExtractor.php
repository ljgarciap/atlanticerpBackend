<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F5 — extrae de ICG (solo lectura, conexión `legacy_icg`) los artículos `ARTICULOS.DESCATALOGADO = 'T'` que tienen `STOCKS.STOCK <> 0` en algún
 * almacén, junto con sus `PRECIOSVENTA` y `PRECIOSCOMPRA`. Reutiliza TAL CUAL el mapeo de filas de `ProductoExtractor` (mapProductoRow/mapPrecioRow/mapCostoRow,
 * incluida la exclusión de la tarifa 4): solo cambian las 3 consultas. `ProductoExtractor` y sus consultas no se tocan, así el flujo de activos queda idéntico.
 *
 * Mismo criterio que el extractor de activos: los precios y costos hacen INNER JOIN a `ARTICULOS` con el MISMO filtro, porque `legacy.producto_precios`/
 * `producto_costos` tienen FK a `legacy.productos` (una fila de un artículo no incluido violaría la FK). El predicado de stock usa un alias propio (`sx`) para no
 * chocar con el LEFT JOIN a AL4 de `articulosQuery()`.
 */
final class ProductoDescatalogadoExtractor implements ProductoDescatalogadoExtractorContract
{
    /** Valor real de ICG para descatalogado (medido 2026-10-15) + stock distinto de cero en cualquier almacén. */
    private const FILTRO = "a.DESCATALOGADO = 'T' AND EXISTS (SELECT 1 FROM STOCKS sx WHERE sx.CODARTICULO = a.CODARTICULO AND sx.STOCK <> 0)";

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): ExtractedProductos
    {
        $articuloRows = DB::connection($this->connection)->select(self::articulosQuery());
        $precioRows   = DB::connection($this->connection)->select(self::preciosQuery());
        $costoRows    = DB::connection($this->connection)->select(self::costosQuery());

        $productos = array_values(array_map(
            static fn (object $row): ProductoRow => ProductoExtractor::mapProductoRow((array) $row),
            $articuloRows,
        ));

        $precios = [];
        foreach ($precioRows as $row) {
            $mapped = ProductoExtractor::mapPrecioRow((array) $row);
            if ($mapped instanceof ProductoPrecioRow) {
                $precios[] = $mapped;
            }
        }

        $costos = array_values(array_map(
            static fn (object $row): ProductoCostoRow => ProductoExtractor::mapCostoRow((array) $row),
            $costoRows,
        ));

        return new ExtractedProductos($productos, $precios, $costos);
    }

    public static function articulosQuery(): string
    {
        return "SELECT a.CODARTICULO, a.DESCRIPCION, a.MARCA, a.FAMILIA, a.SUBFAMILIA,
                   a.REFPROVEEDOR, a.DESCATALOGADO, s.STOCK AS STOCK_AL4
            FROM ARTICULOS a
            LEFT JOIN STOCKS s ON s.CODARTICULO = a.CODARTICULO
                               AND s.CODALMACEN = 'AL4' AND s.TALLA = '.' AND s.COLOR = '.'
            WHERE " . self::FILTRO;
    }

    public static function preciosQuery(): string
    {
        return "SELECT p.CODARTICULO, p.IDTARIFAV, p.PNETO, p.PBRUTO, p.DTO, p.DESCATALOGADO
            FROM PRECIOSVENTA p
            INNER JOIN ARTICULOS a ON a.CODARTICULO = p.CODARTICULO
            WHERE " . self::FILTRO . "
              AND p.TALLA = '.' AND p.COLOR = '.' AND p.CODFORMATO = 0";
    }

    public static function costosQuery(): string
    {
        return "SELECT c.CODPROVEEDOR, c.CODARTICULO, c.PNETO
            FROM PRECIOSCOMPRA c
            INNER JOIN ARTICULOS a ON a.CODARTICULO = c.CODARTICULO
            WHERE " . self::FILTRO;
    }
}
