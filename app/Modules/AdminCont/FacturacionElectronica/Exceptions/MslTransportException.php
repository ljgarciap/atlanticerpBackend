<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

/**
 * Falla de transporte (5xx/timeout) al llamar a MSL — nunca un rechazo fiscal real (eso es 422,
 * mapeado aparte). El circuit breaker la cuenta como fallo; el adapter la traduce a un
 * FiscalDocumentResult "pendiente" incluso antes de que el circuito llegue a abrirse del todo
 * (una sola falla ya debe degradar con gracia, no propagar una excepción sin capturar).
 */
final class MslTransportException extends RuntimeException
{
}
