<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `CONTACTOSCLIENTES` — salida pura de
 * `App\Modules\Legacy\Extractors\ClienteExtractor::mapContactoRow()`, sin dependencia de DB.
 * `icgCodcliente` es la FK cruda de ICG (real, declarada — ver docblock de la migración de
 * `legacy.contactos_cliente`); `App\Modules\Legacy\Services\ClienteStagingLoader` la resuelve al
 * `legacy.clientes.id` interno antes de persistir.
 */
final readonly class ContactoClienteRow
{
    public function __construct(
        public int $icgId,
        public int $icgCodcliente,
        public ?string $cargo,
        public string $nombre,
        public ?string $telefono,
        public ?string $mobil,
        public ?string $email,
    ) {
    }
}
