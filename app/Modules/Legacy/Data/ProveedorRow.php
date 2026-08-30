<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `PROVEEDORES` — salida pura de
 * `App\Modules\Legacy\Extractors\ProveedorExtractor::mapProveedorRow()`, sin dependencia de DB.
 */
final readonly class ProveedorRow
{
    public function __construct(
        public int $icgCodproveedor,
        public string $nombre,
        public ?string $nombreComercial,
        public ?string $cif,
        public ?string $direccion,
        public ?string $telefono1,
        public ?string $email,
    ) {
    }
}
