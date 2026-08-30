<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Error HTTP de la API de Anthropic. Lleva el status code para que BaseAiJob pueda
 * distinguir errores no-reintentables (4xx de request/cuenta, ej. créditos agotados)
 * de errores transitorios (5xx, 429) que sí ameritan el backoff de reintentos.
 */
final class AnthropicApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
