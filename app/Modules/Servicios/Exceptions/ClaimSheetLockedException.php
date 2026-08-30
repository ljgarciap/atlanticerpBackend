<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-278 RN5 — a diferencia de Informe de Inspección (editable después de Completado), la Hoja
 * de Reclamo queda en modo solo lectura TOTAL una vez `estado = completed`. Ver
 * `ClaimSheetService::save()`.
 */
final class ClaimSheetLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta Hoja de Reclamo ya fue completada y no puede editarse.');
    }
}
