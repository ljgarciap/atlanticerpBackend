<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Carbon\CarbonImmutable;

/**
 * Extraído de CommissionService (REQ-073) para que HomeService (REQ-057) pueda
 * reusar el mismo total mensual Aprobado y la misma tabla escalonada sin crear
 * una dependencia circular HomeService -> CommissionService -> ReportsService ->
 * HomeService (ReportsService ya depende de HomeService para vendors()).
 */
final class SalesTotalsService
{
    /** Tabla escalonada REQ-073 — corte único en cada umbral. */
    public function percentFor(float $total): float
    {
        return match (true) {
            $total < 20000  => 1.5,
            $total < 35000  => 2.0,
            $total < 60000  => 3.0,
            $total < 80000  => 4.0,
            $total < 120000 => 4.5,
            default         => 5.0,
        };
    }

    public function monthlyApprovedTotal(User $vendor, CarbonImmutable $monthStart): float
    {
        return (float) PipelineCard::where('owner_id', $vendor->id)
            ->where('stage', 'approved')
            ->whereBetween('stage_changed_at', [$monthStart, $monthStart->endOfMonth()])
            ->sum('amount');
    }
}
