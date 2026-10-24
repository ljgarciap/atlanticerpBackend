<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-879 (decisión E-2/E-7/E-8) — backfill de los `bank_movements` de salida de caja de las
 * comisiones YA PAGADAS (externas con `fecha_pago`, internas de meses cerrados) desde la fecha de
 * corte del módulo Flujo de Caja. Idempotente. Se corre en dev/test dentro del batch; en prod en
 * ventana coordinada con Luis, con captura del saldo de bancos y la Vista Real antes/después.
 */
final class CommissionsBackfillCashMovementsCommand extends Command
{
    protected $signature = 'commissions:backfill-cash-movements
        {--tenant=atlantic : Slug del tenant}';

    protected $description = 'SCRUM-879 — backfill de movimientos de caja de comisiones ya pagadas';

    public function handle(ArchitectCommissionService $external, CommissionOrderService $internal): int
    {
        $tenantSlug = (string) $this->option('tenant');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $externas = $external->backfillCashMovements();
        $internas = $internal->settleInternalCommissionsToCashLedger();

        $this->info("[{$tenantSlug}] Movimientos creados — externas: {$externas}, internas: {$internas}.");

        return self::SUCCESS;
    }
}
