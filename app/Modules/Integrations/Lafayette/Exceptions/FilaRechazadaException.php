<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Exceptions;

/**
 * SCRUM-990 (P1) — una fila de la fuente externa no se puede aceptar (identificador vacío o demasiado largo, etc.). El mapper la pone en
 * CUARENTENA: se omite, se cuenta por motivo y la sincronización continúa. El motivo es un código estable (nunca datos de la fila).
 */
final class FilaRechazadaException extends \InvalidArgumentException
{
    public const ID_VACIO = 'id_vacio';

    public const ID_INVALIDO = 'id_invalido';

    public const ID_DEMASIADO_LARGO = 'id_demasiado_largo';

    public const REFERENCIA_DEMASIADO_LARGA = 'referencia_demasiado_larga';

    public const RECHAZADA_POR_BD = 'rechazada_por_bd';

    public function __construct(public readonly string $motivo)
    {
        parent::__construct($motivo);
    }
}
