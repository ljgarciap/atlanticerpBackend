<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * SCRUM-292/297 (REQ-229/234) — bloqueos de reglas de negocio del ciclo de vida de la Cotización,
 * con un único mensaje humano: intentar generar una cotización cuando el Informe de Inspección
 * todavía no está Completado/No aplica (REQ-229 RN1, reusa el gate de REQ-218/219), editar una
 * cotización que ya no está en Borrador, enviar sin ítems, generar una nueva cotización cuando la
 * más reciente no está Rechazada, o decidir (Aprobar/Rechazar) una que no está Enviada. Mismo
 * patrón minimalista que `App\Modules\VentasDiseno\Exceptions\QuoteActionBlockedException`.
 */
final class ServiceQuoteActionBlockedException extends \RuntimeException
{
}
