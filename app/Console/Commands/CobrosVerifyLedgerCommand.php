<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Services\PaymentLedgerVerifier;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-1168 F1 — compara el libro de movimientos con `invoices.saldo_aplicado` y con el origen de cada movimiento de dinero (ver `PaymentLedgerVerifier`).
 * Sale con código 1 y deja un error en el log si hay diferencias: es la señal del modo sombra. Programado cada noche.
 */
final class CobrosVerifyLedgerCommand extends Command
{
    protected $signature   = 'cobros:verificar-libro {--detalle : imprime cada diferencia}';
    protected $description = 'SCRUM-1168 — verifica que el libro de movimientos coincida con el saldo aplicado de las facturas';

    public function handle(PaymentLedgerVerifier $verifier): int
    {
        $diferencias = 0;

        foreach (Tenant::all() as $tenant) {
            Tenant::forgetCurrent();
            $tenant->makeCurrent();

            try {
                $r = $verifier->verify();
                $n = count($r['facturas']) + count($r['clientes']) + count($r['anuladas']);
                $diferencias += $n;
                $this->line("[{$tenant->slug}] facturas con diferencia: ".count($r['facturas']).', clientes con diferencia de disponible: '.count($r['clientes']).', anuladas con dinero aplicado sin respaldo: '.count($r['anuladas']));

                if ($n > 0) {
                    Log::error('cobros.libro.diferencias', ['tenant' => $tenant->slug, 'facturas' => count($r['facturas']), 'clientes' => count($r['clientes']), 'anuladas' => count($r['anuladas'])]);
                }
                if ($this->option('detalle')) {
                    foreach ($r['facturas'] as $f) {
                        $this->warn("  factura {$f['numero']} (#{$f['invoice_id']}): saldo_aplicado {$f['saldo_aplicado']} vs libro {$f['libro']} + notas {$f['notas']} (dif {$f['diferencia']})");
                    }
                    foreach ($r['anuladas'] as $a) {
                        $this->warn("  factura anulada {$a['numero']} (#{$a['invoice_id']}): conserva {$a['libro']} aplicado por cobros sin nota de anulación; use cobros:trasladar-pagos-correcciones");
                    }
                    foreach ($r['clientes'] as $c) {
                        $this->warn("  cliente #{$c['master_client_id']}: disponible libro {$c['libro']} vs cobros {$c['cobros']} (dif {$c['diferencia']})");
                    }
                }
            } finally {
                Tenant::forgetCurrent();
            }
        }

        return $diferencias === 0 ? self::SUCCESS : self::FAILURE;
    }
}
