<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\CommissionTier;
use App\Modules\VentasDiseno\Models\SalesCommissionTier;

/**
 * Fase 14g — compara la escala de comisiones internas de Admin & Contab con la de Ventas (mismos porcentajes, mismos cortes). Las dos tablas son
 * independientes a propósito (cada empresa puede ajustarlas), pero deben coincidir salvo decisión consciente: la pantalla avisa cuando difieren.
 */
final class CommissionScaleComparator
{
    /**
     * @return array{coinciden: bool, admin: list<array{monto_minimo: float, porcentaje: float}>, ventas: list<array{monto_minimo: float, porcentaje: float}>}
     */
    public function comparar(): array
    {
        $admin = $this->normalizar(CommissionTier::query()->orderBy('orden')->get(['monto_minimo', 'porcentaje'])->all());
        $ventas = $this->normalizar(SalesCommissionTier::query()->orderBy('orden')->get(['monto_minimo', 'porcentaje'])->all());

        return ['coinciden' => $admin === $ventas, 'admin' => $admin, 'ventas' => $ventas];
    }

    /**
     * @param  array<int, CommissionTier|SalesCommissionTier>  $tiers
     * @return list<array{monto_minimo: float, porcentaje: float}>
     */
    private function normalizar(array $tiers): array
    {
        return array_values(array_map(
            static fn ($t): array => ['monto_minimo' => round((float) $t->monto_minimo, 2), 'porcentaje' => round((float) $t->porcentaje, 2)],
            $tiers,
        ));
    }
}
