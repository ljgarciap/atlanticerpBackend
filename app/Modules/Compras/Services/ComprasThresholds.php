<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\ComprasSettings;

/**
 * Umbrales de negocio de Compras leídos de `compras.settings` UNA vez por petición (el servicio está registrado como `scoped`):
 * `PurchaseOrder::isCritical()` se evalúa por orden y sin memo sería una consulta por orden.
 */
final class ComprasThresholds
{
    private ?int $criticalDaysPorAprobar = null;

    /** REQ-139 RN2 — días «Por aprobar» a partir de los cuales una orden es crítica (>=). Parametrizable (antes 3 fijo en código). */
    public function criticalDaysPorAprobar(): int
    {
        return $this->criticalDaysPorAprobar ??= max(1, (int) ComprasSettings::current()->critical_days_por_aprobar);
    }
}
