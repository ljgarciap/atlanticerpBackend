<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

/**
 * Fase 6b — se pidió un adaptador de PAC para una empresa sin facturación electrónica (`pac_provider = none`). No es un error de
 * configuración: quien llama debió comprobar `AdminContFiscalSettings::sinFacturacionElectronica()` antes. Existe para fallar fuerte y claro
 * si algún camino futuro se salta ese chequeo, en vez de enviar un documento a un PAC que no corresponde.
 */
final class SinFacturacionElectronicaException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La empresa no tiene facturación electrónica (pac_provider = none): los documentos son internos y no se envían a ningún PAC.');
    }
}
