<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-248 RN2 — límite configurable de adjuntos por ticket ya alcanzado. Mismo shape que
 * `TicketCloseBlockedException`: el Controller la captura y responde 422 con `message`.
 */
final class TicketAttachmentLimitExceededException extends \RuntimeException
{
    public function __construct(int $maxArchivos)
    {
        parent::__construct("Este ticket ya alcanzó el máximo de {$maxArchivos} archivos adjuntos.");
    }
}
