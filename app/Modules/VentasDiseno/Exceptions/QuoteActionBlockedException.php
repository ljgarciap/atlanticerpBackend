<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

/**
 * SCRUM-734 — bloqueos de reglas de negocio con un único mensaje humano (a
 * diferencia de QuoteValidationBlockedException, que lista campos faltantes):
 * editar una cotización ya confirmada, confirmar sobre una tarjeta que ya avanzó
 * más allá de Cotización o está Perdida, o duplicar fuera de la ventana permitida.
 */
final class QuoteActionBlockedException extends \RuntimeException
{
}
