<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `CLIENTES` — salida pura de
 * `App\Modules\Legacy\Extractors\ClienteExtractor::mapClienteRow()`, sin dependencia de DB.
 */
final readonly class ClienteRow
{
    public function __construct(
        public int $icgCodcliente,
        public ?string $nombreComercial,
        public string $razonSocial,
        public ?string $cif,
        public ?string $direccion,
        public ?string $poblacion,
        public ?string $provincia,
        public ?string $pais,
        public ?string $telefono1,
        public ?string $telefono2,
        public ?string $mobil,
        public ?string $email,
        public ?int $icgCodvendedor,
        public bool $bloqueado,
        public ?DateTimeImmutable $fechaModificadoIcg,
    ) {
    }
}
