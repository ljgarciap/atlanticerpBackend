<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

/**
 * SCRUM-1121 — no se puede quitar de los Asociados de un proyecto al arquitecto que sigue siendo el «Arquitecto» de una de sus cotizaciones
 * (el que origina la comisión de Admin&Cont): primero hay que cambiarlo en esa cotización. Un único mensaje humano para el usuario.
 */
final class AssociateInUseException extends \RuntimeException
{
}
