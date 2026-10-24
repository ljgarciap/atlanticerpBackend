<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** SCRUM-902 — violación de una regla de estado de un Abono (ej. editar uno ya confirmado). */
final class AbonoStateException extends \RuntimeException {}
