<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F2 — dinero disponible (saldo a favor) de los cobros del concepto nuevo, leído del libro de movimientos. El saldo de un cobro es
 * lo que el libro dice que sigue sin aplicar (ingreso − aplicado − devuelto); los abonos históricos y las notas de crédito conservan sus
 * propias fuentes (ver `AbonoService` y `SaldoFavorService`).
 */
final class ClientBalanceService
{
    /**
     * Saldo sin aplicar de cada cobro del concepto nuevo, confirmado y con algo disponible.
     *
     * @param list<int>|null $masterClientIds null = todos los clientes
     * @return array<int, array{master_client_id: int, remanente: float}> payment_id => datos
     */
    public function remanentesPorCobro(?array $masterClientIds = null): array
    {
        $movements = (new AdminContPaymentMovement())->getTable();
        $payments  = (new AdminContPayment())->getTable();

        $query = DB::table("{$movements} as m")
            ->join("{$payments} as p", 'p.id', '=', 'm.payment_id')
            ->where('p.cobros_v2', true)
            ->where('p.estado', AdminContPayment::ESTADO_CONFIRMADO)
            ->groupBy('m.payment_id', 'p.master_client_id')
            ->havingRaw('SUM(m.delta_disponible) > 0.004')
            ->selectRaw('m.payment_id, p.master_client_id, SUM(m.delta_disponible)::float AS remanente');

        if ($masterClientIds !== null) {
            $query->whereIn('p.master_client_id', $masterClientIds);
        }

        $out = [];
        foreach ($query->get() as $r) {
            $out[(int) $r->payment_id] = ['master_client_id' => (int) $r->master_client_id, 'remanente' => round((float) $r->remanente, 2)];
        }

        return $out;
    }

    /**
     * Total sin aplicar por cliente.
     *
     * @param list<int> $masterClientIds
     * @return array<int, float> master_client_id => remanente
     */
    public function remanentePorCliente(array $masterClientIds): array
    {
        $out = [];
        foreach ($this->remanentesPorCobro($masterClientIds) as $r) {
            $out[$r['master_client_id']] = round(($out[$r['master_client_id']] ?? 0.0) + $r['remanente'], 2);
        }

        return $out;
    }
}
