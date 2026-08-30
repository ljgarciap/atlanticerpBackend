<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-218 RN4 — el gate de cierre (Resuelto/Cerrado) bloqueado por Cotización/Informe de
 * Inspección pendiente. Mismo shape que `App\Modules\Bodega\Exceptions\OrderTransitionException`:
 * el Controller la captura y responde 422 con `message` + `errors` (mismo formato que
 * `ValidationException` de Laravel, para que el frontend maneje ambos casos igual).
 */
final class TicketCloseBlockedException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors !== [] ? $this->errors : ['estado' => [$this->getMessage()]];
    }

    public function status(): int
    {
        return $this->status;
    }
}
