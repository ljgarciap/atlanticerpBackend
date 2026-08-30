<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** Batch 8 — Estado de Cuenta (REQ-453/455) — cliente inexistente, o proyecto que no pertenece al
 *  cliente seleccionado. */
final class AccountStatementException extends \RuntimeException {}
