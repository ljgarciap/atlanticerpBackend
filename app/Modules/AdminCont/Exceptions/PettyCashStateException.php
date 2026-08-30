<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/**
 * Batch 20 (REQ-538/540) — violación de una regla de estado de Caja Chica (ej. incluir en un
 * reporte un gasto que ya está en otro, aprobar un reporte que ya no está pendiente). Mismo
 * patrón que `DailyCashCountStateException`/`CreditNoteStateException`.
 */
final class PettyCashStateException extends \RuntimeException {}
