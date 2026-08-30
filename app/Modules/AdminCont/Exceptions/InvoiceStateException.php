<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** Batch 4 de Facturación (REQ-447→451) — violación de una regla de estado/flujo de cobrabilidad
 *  (ej. proponer incobrable sobre una entrega no facturada, o duplicar una propuesta pendiente). */
final class InvoiceStateException extends \RuntimeException {}
