<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-271 RN3 / REQ-272 RN3 — una solicitud ya marcada "Recibida" no puede volver a generar
 * unidades (idempotencia — evita duplicar códigos/tools ante un doble submit o un reintento).
 */
final class ToolPurchaseRequestAlreadyReceivedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta solicitud ya fue marcada como recibida.');
    }
}
