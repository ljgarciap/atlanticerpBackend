<?php

declare(strict_types=1);

namespace App\Modules\Compras\Support;

/**
 * SCRUM-1127 — la versión en otro idioma de una orden no se pudo generar o no se puede usar. `$reason`:
 * `failed` (la IA falló/tardó/devolvió algo inválido), `busy` (otro usuario la está generando), `missing` (no existe todavía) o `stale`
 * (existe pero la orden cambió después). El mensaje es apto para mostrarse al usuario.
 */
final class TranslationException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
