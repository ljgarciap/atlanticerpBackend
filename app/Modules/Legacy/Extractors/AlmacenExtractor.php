<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\AlmacenExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;

/**
 * SCRUM-1136 F1 — ALMACEN de ICG (11 filas). `NOTAS` (ntext) se copia como text; `VERSION` (binaria) se
 * excluye en `IcgTablaExtractor::selectableColumns()`. Ver docblock de la base para el porqué del
 * descubrimiento de columnas y la paginación.
 */
final class AlmacenExtractor extends IcgTablaExtractor implements AlmacenExtractorContract
{
    protected static function tabla(): string
    {
        return 'ALMACEN';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['CODALMACEN'];
    }

    public static function mapRow(array $row): array
    {
        return IcgRowMapper::map(
            $row,
            [
                'codalmacen' => ['CODALMACEN', 'string'],
            ],
            [
                'nombrealmacen' => ['NOMBREALMACEN', 'string'],
                'poblacion' => ['POBLACION', 'string'],
                'provincia' => ['PROVINCIA', 'string'],
                'notas' => ['NOTAS', 'text'],
                'n' => ['N', 'string'],
                'centrocoste' => ['CENTROCOSTE', 'string'],
                'stockminimo' => ['STOCKMINIMO', 'float'],
                'stockmaximo' => ['STOCKMAXIMO', 'float'],
                'factor' => ['FACTOR', 'float'],
                'direccion' => ['DIRECCION', 'string'],
                'telefono' => ['TELEFONO', 'string'],
                'fax' => ['FAX', 'string'],
                'serietraspasos' => ['SERIETRASPASOS', 'string'],
                'codempresacontable' => ['CODEMPRESACONTABLE', 'int'],
                'valoraciontraspasos' => ['VALORACIONTRASPASOS', 'int'],
                'valoracioninventario' => ['VALORACIONINVENTARIO', 'int'],
                'pvpetiquetas' => ['PVPETIQUETAS', 'int'],
                'poperacional' => ['POPERACIONAL', 'string'],
                'codcliente' => ['CODCLIENTE', 'int'],
                'fechaultrep' => ['FECHAULTREP', 'datetime'],
                'ultfacturacionprestamos' => ['ULTFACTURACIONPRESTAMOS', 'datetime'],
                'fechabloqueo' => ['FECHABLOQUEO', 'datetime'],
                'valoracionconsumos' => ['VALORACIONCONSUMOS', 'int'],
                'valoraciontraspasosdmn' => ['VALORACIONTRASPASOSDMN', 'int'],
                'valoracioninventariosdmn' => ['VALORACIONINVENTARIOSDMN', 'int'],
                'esmermas' => ['ESMERMAS', 'bool'],
                'esplanificacion' => ['ESPLANIFICACION', 'bool'],
                'email' => ['EMAIL', 'string'],
                'horacambioturno' => ['HORACAMBIOTURNO', 'datetime'],
                'codpostal' => ['CODPOSTAL', 'string'],
                'codpais' => ['CODPAIS', 'string'],
                'serietraspasostranporte' => ['SERIETRASPASOSTRANPORTE', 'string'],
            ],
            self::tabla(),
        );
    }
}
