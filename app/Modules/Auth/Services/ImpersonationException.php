<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use RuntimeException;

/**
 * SCRUM-1158 — rechazo de negocio de «Ver como usuario». Lleva el estado HTTP y el `code` estable que consume el frontend; el controlador lo
 * traduce a JSON. El mensaje ya viene traducido.
 */
final class ImpersonationException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
