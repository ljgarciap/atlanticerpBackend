<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Carbon\Carbon;

/**
 * SCRUM-908 — elemento uniforme de la tarjeta "Aprobaciones Pendientes" del Home de cada módulo.
 * `tipo` es el identificador estable del tipo de aprobación (mismo vocabulario que
 * `AprobacionGate`/`GerenciaAprobacionController` para los 5 tipos que ambos comparten), `label`
 * es el texto ya armado para mostrar en la tarjeta, `detailRoute` es una ruta de FRONTEND (no de
 * API) — casi siempre la pantalla de listado del tipo (patrón ya usado por
 * `GerenciaHomeService::pendingApprovalsSection()`), no un deep-link por id salvo que ya exista.
 */
final class ApprovalPendingItem
{
    public function __construct(
        public readonly string $tipo,
        public readonly string $label,
        public readonly Carbon $createdAt,
        public readonly string $detailRoute,
    ) {
    }

    /** @return array{tipo: string, label: string, created_at: string, detail_route: string} */
    public function toArray(): array
    {
        return [
            'tipo'         => $this->tipo,
            'label'        => $this->label,
            'created_at'   => $this->createdAt->toIso8601String(),
            'detail_route' => $this->detailRoute,
        ];
    }
}
