<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

/**
 * Señal interna para que PollMslDocumentEstadoJob reintente vía el backoff normal de la cola —
 * nunca se expone fuera del Job (ver GUIA_ILUMINATION.md §6.1: reintentar la consulta cada 1-2
 * min hasta CERTIFICADO o RECHAZADO).
 */
final class DocumentoAunPendienteException extends RuntimeException
{
    public function __construct(string $serie, string $numero)
    {
        parent::__construct("Documento {$serie}/{$numero} sigue PENDIENTE en MSL.");
    }
}
