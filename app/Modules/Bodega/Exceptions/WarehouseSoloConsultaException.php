<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Exceptions;

/**
 * SCRUM-990 — se intentó escribir stock en una bodega de solo consulta (Zona Libre / Lafayette). No es un error de
 * usuario normal: las validaciones de los endpoints ya lo rechazan con 422 antes de llegar al modelo; esto es la red
 * de seguridad para código interno que olvide la validación.
 */
final class WarehouseSoloConsultaException extends \DomainException
{
    public function __construct(public readonly int $warehouseId)
    {
        parent::__construct("La bodega {$warehouseId} es de solo consulta: no admite stock.");
    }
}
