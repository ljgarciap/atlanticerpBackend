<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F2 — dinero disponible (saldo a favor) de los cobros del concepto nuevo, leído del libro de movimientos. El saldo de un cobro es
 * lo que el libro dice que sigue sin aplicar (ingreso − aplicado − devuelto); las notas de crédito con resultado `saldo_favor` conservan su
 * propia fuente (ver `saldoNotasDisponible()`).
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

    /**
     * Saldo a favor de las notas de crédito `saldo_favor` aplicadas de un cliente. `$bloquear` (solo dentro de una transacción) toma `FOR UPDATE`.
     */
    public function saldoNotasDisponible(int $masterClientId, bool $bloquear = false): float
    {
        $query = AdminContCreditNote::query()
            ->where('master_client_id', $masterClientId)
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA);
        if ($bloquear) {
            $query->orderBy('id')->lockForUpdate();
        }

        return round((float) $query->get()->sum(fn (AdminContCreditNote $n) => $n->montoDisponibleSaldoFavor()), 2);
    }

    /**
     * Desglose del saldo a favor de un cliente: `aplicable` (notas de crédito), `a_cuenta` (cobros sin aplicar, del libro) y `total`. `reservado`
     * (abonos ligados a un proyecto o factura) se conserva en la respuesta por compatibilidad y vale siempre 0 desde la Fase 15a.
     *
     * @return array{aplicable: float, a_cuenta: float, reservado: float, total: float}
     */
    public function desglose(int $masterClientId): array
    {
        $aplicable = $this->saldoNotasDisponible($masterClientId);
        $aCuenta   = (float) ($this->remanentePorCliente([$masterClientId])[$masterClientId] ?? 0.0);

        return ['aplicable' => $aplicable, 'a_cuenta' => round($aCuenta, 2), 'reservado' => 0.0, 'total' => round($aplicable + $aCuenta, 2)];
    }

    /**
     * Saldo a favor de notas de crédito por cliente, en UNA consulta (para listados con cientos de filas).
     *
     * @param list<int> $masterClientIds
     * @return array<int, float> master_client_id => saldo (> 0); los clientes sin saldo no aparecen
     */
    public function saldoNotasPorCliente(array $masterClientIds): array
    {
        if ($masterClientIds === []) {
            return [];
        }

        $out = [];
        $notas = AdminContCreditNote::query()
            ->whereIn('master_client_id', array_values(array_unique($masterClientIds)))
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->get();
        foreach ($notas as $nota) {
            $out[(int) $nota->master_client_id] = ($out[(int) $nota->master_client_id] ?? 0.0) + $nota->montoDisponibleSaldoFavor();
        }

        return array_filter(array_map(fn (float $v): float => round($v, 2), $out), fn (float $v): bool => $v > 0.004);
    }
}
