<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/**
 * Batch 17 (SCRUM-591→596) — violación de una regla de estado/flujo de Comisiones Externas (ej.
 * marcar como pagada sin cuenta de cobro, proponer % sobre un proyecto ya pagado). Mismo patrón
 * que `CreditNoteStateException`.
 */
final class ArchitectCommissionStateException extends \RuntimeException {}
