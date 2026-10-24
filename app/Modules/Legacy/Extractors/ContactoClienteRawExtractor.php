<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ContactoClienteRawExtractorContract;
use App\Modules\Legacy\Support\ClienteRawColumnas;
use App\Modules\Legacy\Support\IcgRowMapper;
use App\Modules\Legacy\Support\RowHasher;

/**
 * SCRUM-1137 F1 — CONTACTOSCLIENTES de ICG completa (821 filas, 10 columnas). `ID` es nullable en ICG y la
 * tabla no tiene PK, pero en los datos es único y sin nulos: se usa como llave natural y un `ID` nulo lanza
 * RuntimeException (`IcgRowMapper::map()`, mensaje de llave nula; no imprime valores de otras columnas).
 * Mapa generado desde `docs/architecture/icg-clientes-esquema-icg.txt`.
 */
final class ContactoClienteRawExtractor extends IcgTablaExtractor implements ContactoClienteRawExtractorContract
{
    protected static function tabla(): string
    {
        return 'CONTACTOSCLIENTES';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['ID'];
    }

    /** @return list<string> */
    protected static function columnasExcluidas(): array
    {
        return ClienteRawColumnas::EXCLUIDAS;
    }

    public static function mapRow(array $row): array
    {
        $mapped = IcgRowMapper::map(
            $row,
            [
                'id' => ['ID', 'int'],
            ],
            [
                'codcliente'  => ['CODCLIENTE', 'int'],
                'cargo'       => ['CARGO', 'string'],
                'nombre'      => ['NOMBRE', 'string'],
                'telefono'    => ['TELEFONO', 'string'],
                'e_mail'      => ['E_MAIL', 'string'],
                'dptoedi'     => ['DPTOEDI', 'string'],
                'facturacion' => ['FACTURACION', 'bool'],
                'tesoreria'   => ['TESORERIA', 'bool'],
                'mobil'       => ['MOBIL', 'string'],
            ],
            self::tabla(),
        );

        // Hash de las columnas copiadas (sin source_hash): el loader no reescribe filas que no cambiaron.
        $mapped['source_hash'] = RowHasher::hash($mapped);

        return $mapped;
    }
}
