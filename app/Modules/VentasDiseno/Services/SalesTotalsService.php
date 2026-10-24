<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesCommissionTier;
use Carbon\CarbonImmutable;

/**
 * Extraído de CommissionService (REQ-073) para que HomeService (REQ-057) pueda
 * reusar el mismo total mensual Aprobado y la misma tabla escalonada sin crear
 * una dependencia circular HomeService -> CommissionService -> ReportsService ->
 * HomeService (ReportsService ya depende de HomeService para vendors()).
 */
final class SalesTotalsService
{
    /**
     * Tabla escalonada REQ-073 — paramétrica desde SCRUM-944/945 (antes `match(true)`
     * hardcodeado, ver docblock de la migración `..._sales_commission_tiers_table.php` —
     * `monto_maximo` de cada tramo termina en ".99" a propósito para preservar el corte
     * estrictamente-menor original con el modelo de rango inclusivo).
     */
    public function percentFor(float $total): float
    {
        // Sin guarda de $total <= 0 a propósito — el match(true) original devolvía 1.5% (tramo
        // más bajo) para $0, comportamiento preservado acá porque monto_minimo del tramo 1 es 0.
        $tier = SalesCommissionTier::query()
            ->where('monto_minimo', '<=', $total)
            ->where(function ($q) use ($total): void {
                $q->whereNull('monto_maximo')->orWhere('monto_maximo', '>=', $total);
            })
            ->orderBy('orden')
            ->first();

        return $tier->porcentaje ?? 0.0;
    }

    /**
     * SCRUM-849.4 — una tarjeta asignada a Gerencia a N personas (`owner_id` + `compartido_con`,
     * ver PipelineCard::projectIdsOwnedBy()) reparte su monto en partes iguales entre las N: cada
     * asignado (el owner incluido) aporta amount/N a SU propio total mensual, antes de aplicarle
     * la tabla escalonada — así "si son 2 se divide en 2, si son 3 en 3" (RN del ticket) sin
     * romper el criterio ya existente de que el % se calcula sobre el total agregado de cada
     * persona, no por tarjeta individual.
     */
    public function monthlyApprovedTotal(User $vendor, CarbonImmutable $monthStart): float
    {
        $cards = PipelineCard::where('stage', 'approved')
            ->whereBetween('stage_changed_at', [$monthStart, $monthStart->endOfMonth()])
            ->where(function ($q) use ($vendor): void {
                $q->where('owner_id', $vendor->id)
                    ->orWhereJsonContains('compartido_con', $vendor->id);
            })
            ->get(['amount', 'compartido_con']);

        return (float) $cards->sum(fn (PipelineCard $card): float => $this->shareFor($card));
    }

    /** Monto que le corresponde a UN asignado de una tarjeta con N asignados (owner + compartido_con). */
    private function shareFor(PipelineCard $card): float
    {
        $assigneeCount = 1 + count($card->compartido_con ?? []);

        return ((float) ($card->amount ?? 0)) / $assigneeCount;
    }
}
