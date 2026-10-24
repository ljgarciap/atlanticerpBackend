<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\InventarioCabExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;

/**
 * SCRUM-1136 F1 — INVENTARIOS de ICG (~215 filas, solo cabeceras; INVENTARIOSZONA está vacía y no se
 * extrae). Llave natural `FECHA, CODALMACEN`.
 */
final class InventarioCabExtractor extends IcgTablaExtractor implements InventarioCabExtractorContract
{
    protected static function tabla(): string
    {
        return 'INVENTARIOS';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['FECHA', 'CODALMACEN'];
    }

    public static function mapRow(array $row): array
    {
        return IcgRowMapper::map(
            $row,
            [
                'fecha' => ['FECHA', 'datetime'],
                'codalmacen' => ['CODALMACEN', 'string'],
            ],
            [
                'tipovaloracion' => ['TIPOVALORACION', 'int'],
                'serie' => ['SERIE', 'string'],
                'numero' => ['NUMERO', 'int'],
                'codvendedor' => ['CODVENDEDOR', 'int'],
                'completo' => ['COMPLETO', 'string'],
                'metodo' => ['METODO', 'int'],
                'inicial' => ['INICIAL', 'string'],
                'bloqueado' => ['BLOQUEADO', 'string'],
                'tipovaloraciondmn' => ['TIPOVALORACIONDMN', 'int'],
                'estado' => ['ESTADO', 'int'],
                'escierre' => ['ESCIERRE', 'bool'],
                'enlace_ejercicio' => ['ENLACE_EJERCICIO', 'int'],
                'enlace_empresa' => ['ENLACE_EMPRESA', 'int'],
                'enlace_usuario' => ['ENLACE_USUARIO', 'string'],
                'enlace_asiento' => ['ENLACE_ASIENTO', 'int'],
            ],
            self::tabla(),
        );
    }
}
