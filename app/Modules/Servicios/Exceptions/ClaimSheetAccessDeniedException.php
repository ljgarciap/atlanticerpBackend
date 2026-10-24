<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-278 — solo Lider Servicios/Líder de Servicios (superadmin) o el técnico interno asignado a ESE ticket
 * pueden generar/editar su Hoja de Reclamo. `role:` en la ruta filtra por rol; esto cubre lo que
 * una ruta no puede expresar — ownership del ticket puntual — ver
 * `ClaimSheetService::assertCanEdit()`. Mismo patrón que `InspectionReportAccessDeniedException`.
 */
final class ClaimSheetAccessDeniedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Solo el Líder de Servicios o el técnico interno asignado a este ticket pueden generar o editar su Hoja de Reclamo.');
    }
}
