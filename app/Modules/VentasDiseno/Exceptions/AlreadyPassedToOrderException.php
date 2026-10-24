<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

/**
 * SCRUM-1128 — el proyecto ya completó "Pasar a Pedido" (doble clic, dos pestañas, recarga): el
 * controller la traduce a 409 con el estado actual de la tarjeta, sin generar otro pedido.
 */
final class AlreadyPassedToOrderException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este proyecto ya fue pasado a pedido.');
    }
}
