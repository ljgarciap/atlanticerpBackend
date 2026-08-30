<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Exceptions;

/**
 * SCRUM-329 Oleada A / Batch A2 — violación de una regla de negocio del flujo de Pedidos
 * (transición inválida, precondición faltante, tope excedido, etc.). Los Services de este batch
 * la lanzan; los Controllers la capturan y responden 422 con `message` + `errors` (mismo shape que
 * `ValidationException` de Laravel, para que el frontend maneje ambos casos igual).
 */
final class OrderTransitionException extends \RuntimeException
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
        return $this->errors !== [] ? $this->errors : ['order' => [$this->getMessage()]];
    }

    public function status(): int
    {
        return $this->status;
    }
}
