<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Services\PaymentLedgerBackfill;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1168 F1 — copia el historial de cobros y abonos al libro de movimientos. Idempotente. La corre también la migración de datos del
 * despliegue; este comando existe para repetirla a mano si hiciera falta (por ejemplo tras la ventana entre subir el código y migrar).
 */
final class CobrosBackfillLedgerCommand extends Command
{
    protected $signature   = 'cobros:backfill-libro';
    protected $description = 'SCRUM-1168 — copia el historial de cobros y abonos al libro de movimientos (idempotente)';

    public function handle(PaymentLedgerBackfill $backfill): int
    {
        foreach (Tenant::all() as $tenant) {
            Tenant::forgetCurrent();
            $tenant->makeCurrent();

            try {
                $r = $backfill->run();
                $this->line("[{$tenant->slug}] abonos: {$r['abonos']}, consumos: {$r['consumos']}, aplicaciones: {$r['aplicaciones']}");
            } finally {
                Tenant::forgetCurrent();
            }
        }

        return self::SUCCESS;
    }
}
