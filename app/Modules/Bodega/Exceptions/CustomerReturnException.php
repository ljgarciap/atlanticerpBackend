<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Exceptions;

/**
 * SCRUM-473→489 (Bloque B6 "Devoluciones") — violación de una regla de negocio del ciclo de
 * Devoluciones (tope de cantidad excedido, transición de estado inválida, precondición faltante,
 * etc.). Mismo patrón que `App\Modules\Bodega\Exceptions\OrderTransitionException`: los Services
 * la lanzan, los Controllers la capturan y responden 422 con `message` + `errors` (mismo shape que
 * `ValidationException` de Laravel).
 */
final class CustomerReturnException extends \RuntimeException
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
        return $this->errors !== [] ? $this->errors : ['customer_return' => [$this->getMessage()]];
    }

    public function status(): int
    {
        return $this->status;
    }
}
