<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/**
 * Batch 13 (REQ-494) — violación de una regla de estado/flujo de aprobación de una Nota de
 * Crédito (ej. decidir sobre una nota que ya no está pendiente, o aprobar una que exige
 * comprobante y no lo tiene adjunto). Mismo patrón que `InvoiceStateException`.
 */
final class CreditNoteStateException extends \RuntimeException {}
