<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/**
 * Batch 19 (REQ-526/528) — violación de una regla de estado del ciclo cierre/aprobación de un
 * Arqueo del día (ej. editar un arqueo que ya no está `abierto`, cerrar uno que ya se cerró,
 * aprobar uno que no está `pendiente_aprobacion`). Mismo patrón que `CreditNoteStateException`.
 */
final class DailyCashCountStateException extends \RuntimeException {}
