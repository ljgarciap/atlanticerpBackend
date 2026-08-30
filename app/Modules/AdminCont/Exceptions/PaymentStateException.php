<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** Batch 7 de Cobros (REQ-475) — violación de una regla de estado de un cobro (ej. confirmar uno
 *  que ya no está "Esperando confirmación", o que no existe). */
final class PaymentStateException extends \RuntimeException {}
