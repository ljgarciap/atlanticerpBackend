<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

use App\Modules\Legacy\Contracts\AlmacenExtractorContract;
use App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\InventarioCabExtractorContract;
use App\Modules\Legacy\Contracts\StockAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\TraspasoCabExtractorContract;
use InvalidArgumentException;

/**
 * SCRUM-1136 F1 — registro de las entidades de staging de almacenes/stock/traspasos de ICG: nombre de
 * entidad (el que se escribe en `legacy.sync_runs.entidad` y en `--entidad=`), tabla de staging, llave
 * natural (para el upsert) y el contrato de extractor que la alimenta. Una sola fuente de verdad para el
 * loader, los comandos y el verificador.
 */
final class AlmacenEntidades
{
    /**
     * @var array<string, array{tabla: string, keys: list<string>, extractor: class-string}>
     */
    private const MAPA = [
        'almacenes' => [
            'tabla'     => 'almacenes',
            'keys'      => ['codalmacen'],
            'extractor' => AlmacenExtractorContract::class,
        ],
        'stock_almacen' => [
            'tabla'     => 'stock_almacen',
            'keys'      => ['codarticulo', 'talla', 'color', 'codalmacen'],
            'extractor' => StockAlmacenExtractorContract::class,
        ],
        'costo_almacen' => [
            'tabla'     => 'costo_almacen',
            'keys'      => ['codarticulo', 'talla', 'color', 'codalmacen'],
            'extractor' => CostoAlmacenExtractorContract::class,
        ],
        'traspasos' => [
            'tabla'     => 'traspasos_cab',
            'keys'      => ['serie', 'caja', 'numero'],
            'extractor' => TraspasoCabExtractorContract::class,
        ],
        'inventarios' => [
            'tabla'     => 'inventarios_cab',
            'keys'      => ['fecha', 'codalmacen'],
            'extractor' => InventarioCabExtractorContract::class,
        ],
    ];

    /** @return list<string> */
    public static function todas(): array
    {
        return array_keys(self::MAPA);
    }

    public static function existe(string $entidad): bool
    {
        return isset(self::MAPA[$entidad]);
    }

    public static function tabla(string $entidad): string
    {
        return self::definicion($entidad)['tabla'];
    }

    /** @return list<string> */
    public static function keys(string $entidad): array
    {
        return self::definicion($entidad)['keys'];
    }

    /** @return class-string */
    public static function extractor(string $entidad): string
    {
        return self::definicion($entidad)['extractor'];
    }

    /** @return array{tabla: string, keys: list<string>, extractor: class-string} */
    private static function definicion(string $entidad): array
    {
        return self::MAPA[$entidad]
            ?? throw new InvalidArgumentException("Entidad de almacenes desconocida '{$entidad}'.");
    }
}
