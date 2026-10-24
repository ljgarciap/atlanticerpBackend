<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

use App\Modules\Legacy\Contracts\ClienteRawExtractorContract;
use App\Modules\Legacy\Contracts\ContactoClienteRawExtractorContract;
use InvalidArgumentException;

/**
 * SCRUM-1137 F1 — registro de las 2 entidades de la copia cruda de clientes de ICG: tabla de staging,
 * llave natural (para el upsert) y contrato del extractor que la alimenta. Gemelo de `AlmacenEntidades`,
 * separado a propósito para que `legacy:sync-almacenes` (sin `--entidad`) no empiece a correr clientes.
 */
final class ClienteRawEntidades
{
    /** Entidad con la que se registra la corrida en `legacy.sync_runs`. */
    public const SYNC_RUN_ENTIDAD = 'clientes_raw';

    /**
     * @var array<string, array{tabla: string, keys: list<string>, extractor: class-string}>
     */
    private const MAPA = [
        'clientes_raw' => [
            'tabla'     => 'clientes_raw',
            'keys'      => ['codcliente'],
            'extractor' => ClienteRawExtractorContract::class,
        ],
        'contactos_cliente_raw' => [
            'tabla'     => 'contactos_cliente_raw',
            'keys'      => ['id'],
            'extractor' => ContactoClienteRawExtractorContract::class,
        ],
    ];

    /** @return list<string> */
    public static function todas(): array
    {
        return array_keys(self::MAPA);
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
            ?? throw new InvalidArgumentException("Entidad de clientes raw desconocida '{$entidad}'.");
    }
}
