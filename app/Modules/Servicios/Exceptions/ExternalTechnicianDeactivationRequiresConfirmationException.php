<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Exceptions;

/**
 * REQ-265 RN2 — inactivar un técnico externo con proyectos activos no es un bloqueo total, pero
 * exige confirmación explícita del usuario antes de aplicarse. El controller la captura y
 * responde 409 con el conteo, para que el frontend muestre la advertencia y reintente con
 * `confirm: true`.
 */
final class ExternalTechnicianDeactivationRequiresConfirmationException extends \RuntimeException
{
    public function __construct(private readonly int $activeProjectsCount)
    {
        parent::__construct(
            "Este técnico tiene {$activeProjectsCount} proyecto(s) activo(s) — ¿de todas formas quieres inactivarlo?"
        );
    }

    public function activeProjectsCount(): int
    {
        return $this->activeProjectsCount;
    }
}
