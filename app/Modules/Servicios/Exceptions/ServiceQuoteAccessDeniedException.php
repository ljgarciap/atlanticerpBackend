<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * SCRUM-292 (REQ-229 RN) — solo Lider Servicios/Líder de Servicios (superadmin) o el técnico interno
 * asignado a ESTE ticket pueden generar/editar su Cotización de Servicio. Mismo patrón que
 * `InspectionReportAccessDeniedException`/`ClaimSheetAccessDeniedException` — `role:` en la ruta
 * filtra por rol, esto cubre el ownership por ticket puntual que una ruta no puede expresar. Ver
 * `ServiceQuoteService::assertCanEdit()`.
 */
final class ServiceQuoteAccessDeniedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Solo el Líder de Servicios o el técnico interno asignado a este ticket pueden generar o editar su Cotización.');
    }
}
