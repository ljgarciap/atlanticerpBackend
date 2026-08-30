<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCustomerCreditBalance;
use Illuminate\Support\Facades\DB;

/**
 * Batch 3 de Facturación (SCRUM-519→523, REQ-442) — fuente única de verdad de saldo a favor,
 * compartida a futuro por Facturación/Cobros/Estado de Cuenta. Ver docblock de la migración de
 * `customer_credit_balances` — hoy sin ningún escritor real (Notas de Crédito, el único origen
 * legítimo, es un batch muy posterior), así que `forMasterClient()` devuelve 0.0 para todos.
 */
final class CreditBalanceService
{
    public function forMasterClient(int $masterClientId): float
    {
        return (float) (AdminContCustomerCreditBalance::query()
            ->where('master_client_id', $masterClientId)
            ->value('saldo') ?? 0.0);
    }

    /** Resta `$amount` del saldo del cliente — nunca queda negativo (defensivo: hoy nada más que
     *  esta llamada puede reducirlo, pero un monto mayor al disponible no debe generar deuda). */
    public function apply(int $masterClientId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($masterClientId, $amount): void {
            /** @var AdminContCustomerCreditBalance $balance */
            $balance = AdminContCustomerCreditBalance::query()
                ->lockForUpdate()
                ->firstOrCreate(['master_client_id' => $masterClientId], ['saldo' => 0]);

            $balance->update(['saldo' => max(0.0, $balance->saldo - $amount)]);
        });
    }

    /** Suma `$amount` al saldo del cliente — Batch 11 de Notas Crédito (REQ-484 RN2), primer
     *  escritor real de saldo a favor: el excedente de una nota que el usuario elige "dejar como
     *  saldo a favor" en vez de devolver. */
    public function add(int $masterClientId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($masterClientId, $amount): void {
            /** @var AdminContCustomerCreditBalance $balance */
            $balance = AdminContCustomerCreditBalance::query()
                ->lockForUpdate()
                ->firstOrCreate(['master_client_id' => $masterClientId], ['saldo' => 0]);

            $balance->update(['saldo' => $balance->saldo + $amount]);
        });
    }
}
