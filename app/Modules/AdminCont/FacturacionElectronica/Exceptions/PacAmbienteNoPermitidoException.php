<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

/**
 * SCRUM-943 — guard de `PacProviderFactory`: `fiscal_settings.pac_ambiente = 'production'` en un
 * `APP_ENV` de AtlanticERP que no es `production` (dev/qa/local) nunca certifica de verdad — es un error
 * de CONFIGURACIÓN nuestra (mismo criterio que `FiscalDocumentResult::errorConfiguracion()`,
 * Senior Review 2026-09-17 finding #5), no un intento legítimo de tocar producción real de MSL/DGI
 * desde un entorno de pruebas de AtlanticERP. El caller (wiring de SCRUM-943 tarea 2, controllers de
 * Facturación/NC) mapea esto a `error_configuracion`, nunca a `rechazado`.
 */
final class PacAmbienteNoPermitidoException extends RuntimeException
{
    public function __construct(string $appEnv)
    {
        parent::__construct(
            "fiscal_settings.pac_ambiente = 'production' pero APP_ENV = '{$appEnv}' — ".
            'un entorno de AtlanticERP que no es production nunca certifica contra producción real de MSL.'
        );
    }
}
