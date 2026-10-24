<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Services\InvoiceReplacementPaymentTransfer;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1183 — traslada al reemplazo el pago que quedó aplicado a facturas ya corregidas ANTES de que la corrección lo trasladara sola. Sin `--force` solo
 * lista lo que haría (no escribe nada). Idempotente: una corrección sin pago pendiente en la original no se toca. Se procesan en orden de nota, así una
 * cadena de correcciones (A → B → C) arrastra el pago hasta la última factura.
 */
final class CobrosTrasladarPagosCorreccionesCommand extends Command
{
    protected $signature   = 'cobros:trasladar-pagos-correcciones {--force : aplica el traslado (sin esto solo lista)}';
    protected $description = 'SCRUM-1183 — traslada al reemplazo el pago aplicado a facturas corregidas antes del traslado automático';

    public function handle(InvoiceReplacementPaymentTransfer $transfer): int
    {
        foreach (Tenant::all() as $tenant) {
            Tenant::forgetCurrent();
            $tenant->makeCurrent();

            try {
                $this->procesar($tenant->slug, $transfer, (bool) $this->option('force'));
            } finally {
                Tenant::forgetCurrent();
            }
        }

        return self::SUCCESS;
    }

    private function procesar(string $slug, InvoiceReplacementPaymentTransfer $transfer, bool $force): void
    {
        $notas = AdminContCreditNote::query()
            ->where('tipo', AdminContCreditNote::TIPO_ANULACION_COMPLETA)->where('subtipo_anulacion', AdminContCreditNote::SUBTIPO_CORRECCION)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)->whereNotNull('factura_nueva_id')
            ->orderBy('id')->get();

        $pendientes = 0;
        foreach ($notas as $nota) {
            $aplicado = round((float) AdminContPaymentMovement::query()->where('invoice_id', $nota->factura_origen_id)->sum('delta_factura'), 2);
            if ($aplicado <= 0.004) {
                continue;
            }
            $original   = AdminContInvoice::query()->find($nota->factura_origen_id);
            $reemplazo  = AdminContInvoice::query()->find($nota->factura_nueva_id);
            if ($original === null || $reemplazo === null) {
                continue;
            }
            $pendientes++;
            $this->line("[{$slug}] {$nota->numero}: {$original->numero} tiene {$aplicado} aplicado; reemplazo {$reemplazo->numero} (monto {$reemplazo->monto}, aplicado {$reemplazo->saldo_aplicado})");

            if ($force) {
                $movido = DB::transaction(fn (): float => $transfer->transfer(
                    $original, $reemplazo, "Corrección de datos {$nota->numero}: el pago pasa a {$reemplazo->numero}", $nota->aprobado_por ?? $nota->registrado_por,
                ));
                $this->info("  trasladado: {$movido}");
            }
        }

        $this->line("[{$slug}] correcciones con pago pendiente de trasladar: {$pendientes}".($force ? '' : ' (simulación: use --force para aplicar)'));
    }
}
