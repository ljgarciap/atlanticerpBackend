<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Http\Resources\AdminCont\CarteraCommissionTierResource;
use App\Modules\AdminCont\Models\CarteraCommissionTier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Batch 22 (SCRUM-643→647, REQ-567) — comisión de Contabilidad por gestión de cartera vencida +90 días.
 * RN4: nunca depende del selector de período de Reportes, siempre mes en curso — por eso este
 * servicio no toma ningún parámetro de ventana, a diferencia de `CashPositionService::projected()`.
 */
final class CarteraCommissionService
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    /** @return Collection<int, CarteraCommissionTier> */
    public function tiers(): Collection
    {
        return CarteraCommissionTier::query()->orderBy('orden')->get();
    }

    /**
     * @return array{cobrado_mes: float, rango_actual: string, porcentaje: float, comision: float, tiers: AnonymousResourceCollection}
     */
    public function felixCommissionForCurrentMonth(): array
    {
        $cobradoMes = $this->invoices->over90DaysCollectedForCurrentMonth()['cobrado_mes'];
        $tiers      = $this->tiers();

        $tramoActual = $tiers->first(
            fn (CarteraCommissionTier $t) => $t->monto_minimo <= $cobradoMes
                && ($t->monto_maximo === null || $cobradoMes <= $t->monto_maximo),
        );
        // Sin ningún tramo definido (tabla vaciada por error vía CRUD) — degrada a 0, nunca lanza.
        $porcentaje = $tramoActual !== null ? $tramoActual->porcentaje : 0.0;
        $comision   = round($cobradoMes * $porcentaje / 100, 2);

        $tiersPayload = [];
        foreach ($tiers as $t) {
            $tiersPayload[] = [
                'monto_minimo' => $t->monto_minimo,
                'monto_maximo' => $t->monto_maximo,
                'porcentaje'   => $t->porcentaje,
                'orden'        => $t->orden,
                'es_actual'    => $tramoActual !== null && $t->id === $tramoActual->id,
            ];
        }

        return [
            'cobrado_mes'  => $cobradoMes,
            'rango_actual' => $tramoActual !== null ? self::rangoLabel($tramoActual) : '—',
            'porcentaje'   => $porcentaje,
            'comision'     => $comision,
            'tiers'        => CarteraCommissionTierResource::collection($tiersPayload),
        ];
    }

    /**
     * Label genérico armado desde los montos de la tabla — nunca un string fijo por tramo, para
     * que siga siendo correcto si Gerencia edita los límites vía CRUD.
     *
     * `monto_maximo` real de un tramo es un borde inclusivo tipo 14999.99 (ver docblock de la
     * migración) — mostrarlo "tal cual" redondeado al millar más cercano da "$15.0K" en vez de
     * "$15K" (19999.99/1000 redondea a 20, no a 19). Se resuelve con `ceil` para el límite superior
     * de un rango ABIERTO hacia arriba ("Menos de $X") y `floor` para el resto (mínimo de un tramo,
     * y el máximo mostrado de un rango cerrado) — así el borde 14999.99/19999.99 vuelve a leerse
     * como el millar redondo real (15K/19K) sin depender de aritmética +/-0.01 sensible a
     * precisión de punto flotante.
     */
    private static function rangoLabel(CarteraCommissionTier $tier): string
    {
        if ($tier->monto_minimo <= 0 && $tier->monto_maximo !== null) {
            return 'Menos de $'.self::compact($tier->monto_maximo, roundUp: true);
        }
        if ($tier->monto_maximo === null) {
            return 'Más de $'.self::compact($tier->monto_minimo, roundUp: false);
        }

        return '$'.self::compact($tier->monto_minimo, roundUp: false).' – $'.self::compact($tier->monto_maximo, roundUp: false);
    }

    private static function compact(float $amount, bool $roundUp): string
    {
        $thousands = $amount / 1000;
        $whole     = $roundUp ? ceil($thousands) : floor($thousands);

        return ((string) (int) $whole).'K';
    }
}
