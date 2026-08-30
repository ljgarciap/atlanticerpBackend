<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-238 RN5 — solo Aaron/Líder de Servicios (superadmin) y el técnico interno asignado a ESE
 * ticket pueden generar/editar su informe. `role:` en la ruta ya filtra por rol; esto cubre la
 * parte que una ruta no puede expresar — ownership del ticket puntual — ver
 * `InspectionReportService::assertCanEdit()`. El Controller la captura y responde 403.
 */
final class InspectionReportAccessDeniedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Solo Aaron/Líder de Servicios o el técnico interno asignado a este ticket pueden generar o editar su informe de inspección.');
    }
}
