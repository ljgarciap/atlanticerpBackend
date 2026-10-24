<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** SCRUM-1166 — no se puede marcar o quitar la exención de ITBMS de un cliente (falta el documento, el cliente no existe o falta la tasa exenta). */
final class ItbmsExemptionException extends \RuntimeException {}
