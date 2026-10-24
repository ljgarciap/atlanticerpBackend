<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

/**
 * SCRUM-1007 — el reintento manual de certificación / la actualización de estado no se puede
 * ejecutar ahora. `codigo` es estable (lo consume el frontend y los tests); el mensaje va en
 * español para el usuario. El controlador la traduce a HTTP 409.
 */
final class RecertificacionNoPermitidaException extends RuntimeException
{
    public const ESTADO_NO_REINTENTABLE  = 'estado_no_reintentable';
    public const ESTADO_NO_ACTUALIZABLE  = 'estado_no_actualizable';
    public const ESTADO_NO_RECONCILIABLE = 'estado_no_reconciliable';
    public const FE_APAGADA              = 'fe_apagada';
    public const DOCUMENTO_ANULADO       = 'documento_anulado';
    public const NOTA_NO_APLICADA        = 'nota_no_aplicada';
    public const AMBIENTE_DISTINTO       = 'ambiente_distinto';
    public const EN_CURSO                = 'en_curso';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
