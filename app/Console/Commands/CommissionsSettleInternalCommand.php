<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-879 (decisión E-1) — al cierre de cada mes, refleja las comisiones internas liquidadas
 * como salida de caja (`bank_movements`, sin cuenta asignada). Programado DIARIO con guard de
 * fecha interno (`settleInternalCommissionsToCashLedger()` solo toca meses ya cerrados y sin
 * movimiento) — si el server estuvo caído el día 1 no se pierde el mes. Idempotente.
 */
final class CommissionsSettleInternalCommand extends Command
{
    protected $signature   = 'commissions:settle-internal';
    protected $description = 'SCRUM-879 — genera el movimiento de caja de las comisiones internas de meses ya cerrados';

    public function handle(CommissionOrderService $commissions): int
    {
        $total = 0;
        foreach (Tenant::all() as $tenant) {
            Tenant::forgetCurrent();
            $tenant->makeCurrent();

            try {
                $created = $commissions->settleInternalCommissionsToCashLedger();
                $total  += $created;
                $this->line("[{$tenant->slug}] movimientos de comisión interna creados: {$created}");
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("Total de movimientos creados: {$total}.");

        return self::SUCCESS;
    }
}
