<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Exceptions;

/**
 * SCRUM-797 RN5→RN7 (Pre-QA 2026-08-26) — un producto+bodega de la solicitud colisiona con otra
 * `AdjustmentRequestLine` pendiente y el actor no confirmó el reemplazo. Se lanza DENTRO de la
 * transacción de `AdjustmentRequestController::store()` (nunca `return` un array 409 directo desde
 * el closure — `DB::transaction()` solo revierte ante un Throwable, y devolver un valor sin lanzar
 * dejaría committeados el `AdjustmentRequest` contenedor y cualquier línea ya creada en el mismo
 * loop antes de detectar la colisión).
 */
final class AdjustmentDuplicateConflictException extends \RuntimeException
{
    /** @param list<array<string, mixed>> $duplicates */
    public function __construct(private readonly array $duplicates)
    {
        parent::__construct('Uno o más productos ya tienen un conteo o ajuste sin resolver.');
    }

    /** @return list<array<string, mixed>> */
    public function duplicates(): array
    {
        return $this->duplicates;
    }
}
