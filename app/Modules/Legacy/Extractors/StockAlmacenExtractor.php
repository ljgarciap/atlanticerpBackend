<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\StockAlmacenExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;

/**
 * SCRUM-1136 F1 — STOCKS de ICG, todos los almacenes y todos los productos (~181.727 filas), sin filtrar
 * por los ya mapeados. Siempre paginado (ver base). `stock_corregido` = max(stock, 0) se calcula acá, al
 * mapear (reemplaza a la tabla STOCKSNEGATIVOSCOMO0 de ICG, que no se extrae).
 */
final class StockAlmacenExtractor extends IcgTablaExtractor implements StockAlmacenExtractorContract
{
    protected static function tabla(): string
    {
        return 'STOCKS';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['CODARTICULO', 'TALLA', 'COLOR', 'CODALMACEN'];
    }

    public static function mapRow(array $row): array
    {
        $mapped = IcgRowMapper::map(
            $row,
            [
                'codarticulo' => ['CODARTICULO', 'int'],
                'talla' => ['TALLA', 'string'],
                'color' => ['COLOR', 'string'],
                'codalmacen' => ['CODALMACEN', 'string'],
            ],
            [
                'stock' => ['STOCK', 'float'],
                'pedido' => ['PEDIDO', 'float'],
                'aservir' => ['ASERVIR', 'float'],
                'prestado' => ['PRESTADO', 'float'],
                'deposito' => ['DEPOSITO', 'float'],
                'fabricacion' => ['FABRICACION', 'float'],
                'minimo' => ['MINIMO', 'float'],
                'fechamodificado' => ['FECHAMODIFICADO', 'datetime'],
                'maximo' => ['MAXIMO', 'float'],
                'ubicacion' => ['UBICACION', 'string'],
                'fecharegul' => ['FECHAREGUL', 'datetime'],
                'stockregul' => ['STOCKREGUL', 'float'],
                'enreparacion' => ['ENREPARACION', 'float'],
                'entransito' => ['ENTRANSITO', 'float'],
                'merma' => ['MERMA', 'float'],
                'stockcontable' => ['STOCKCONTABLE', 'float'],
                'stock2' => ['STOCK2', 'float'],
                'stockregul2' => ['STOCKREGUL2', 'float'],
            ],
            self::tabla(),
        );

        return self::withStockCorregido($mapped);
    }

    /**
     * Pura. Un STOCK negativo se trata como 0; STOCK nulo queda nulo (sin dato).
     *
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    public static function withStockCorregido(array $mapped): array
    {
        $stock                  = $mapped['stock'] ?? null;
        $mapped['stock_corregido'] = $stock === null ? null : max((float) $stock, 0.0);

        return $mapped;
    }
}
