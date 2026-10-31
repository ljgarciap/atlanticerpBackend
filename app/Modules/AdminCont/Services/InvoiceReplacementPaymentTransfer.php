<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1183 — traslado del pago cuando una factura se CORRIGE (nota de «Corrección de datos»): la original se anula y el reemplazo nace sin cobro, pero el
 * dinero cobrado es del cliente y aplicarlo a una factura es solo una asignación, así que la asignación pasa a la factura que la sustituye.
 *
 * Por cada aplicación vigente a la original (cobros del concepto nuevo o anteriores, abonos, saldo de notas, historial) se reversa en el libro (otro
 * movimiento que apunta al original, nunca una edición) y se vuelve a aplicar al reemplazo, hasta lo que este admite: si el reemplazo vale menos, lo que
 * sobra queda como saldo a favor de su fuente; si vale más, el resto queda pendiente. No es un cruce de un usuario: no genera folio CMP; el motivo lleva el
 * número de la nota. Caja y bancos no se tocan (no entra ni sale dinero) y `invoices.saldo_aplicado` se escribe solo por `InvoiceBalanceWriter`.
 *
 * Se llama dentro de la transacción de la nota, con la factura original ya bloqueada. No toma el bloqueo por cliente: las facturas y los cobros se bloquean
 * por fila y tomarlo después de bloquear la factura invertiría el orden de bloqueos de los cobros (cobro → cliente → facturas).
 */
final class InvoiceReplacementPaymentTransfer
{
    public function __construct(
        private readonly InvoiceBalanceWriter $balance,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * @return float lo que quedó aplicado al reemplazo
     */
    public function transfer(AdminContInvoice $original, AdminContInvoice $replacement, string $motivo, ?int $actorId): float
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('InvoiceReplacementPaymentTransfer::transfer() debe correr dentro de una transacción.');
        }

        /** @var AdminContInvoice $original */
        $original = AdminContInvoice::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
        /** @var AdminContInvoice $replacement */
        $replacement = AdminContInvoice::query()->whereKey($replacement->id)->lockForUpdate()->firstOrFail();

        $reversados = AdminContPaymentMovement::query()
            ->whereIn('reverses_movement_id', AdminContPaymentMovement::query()->where('invoice_id', $original->id)->select('id'))
            ->pluck('reverses_movement_id')->flip()->map(fn () => true)->all();

        // Aplicaciones vigentes: las que dejó un cobro/abono/saldo de nota (`aplicacion`) y las del historial (`historico`). Un cobro anulado ya se reversó
        // por completo con sus propios contra-movimientos, así que no cuenta.
        $vigentes = AdminContPaymentMovement::query()
            ->where('invoice_id', $original->id)
            ->whereIn('tipo', [AdminContPaymentMovement::TIPO_APLICACION, AdminContPaymentMovement::TIPO_HISTORICO])
            ->where('delta_factura', '>', 0)
            ->orderBy('id')->get()
            ->filter(fn (AdminContPaymentMovement $m): bool => ! isset($reversados[$m->id]));

        $cobros   = AdminContPayment::query()->whereIn('id', $vigentes->pluck('payment_id')->filter()->unique())->get()->keyBy('id');
        $vigentes = $vigentes->filter(fn (AdminContPaymentMovement $m): bool => $m->payment_id === null || $cobros->get($m->payment_id)?->estado !== AdminContPayment::ESTADO_ANULADO)->values();

        if ($vigentes->isEmpty()) {
            return 0.0;
        }

        $capacidad = round(max(0.0, (float) $replacement->monto - (float) $replacement->saldo_aplicado), 2);
        $totalOriginal = 0.0;
        $totalTrasladado = 0.0;
        /** @var array<int, array{aplicado: float, trasladado: float}> $porCobro */
        $porCobro = [];

        foreach ($vigentes as $m) {
            $monto = round((float) $m->delta_factura, 2);
            $deNota = $m->payment_id === null;
            // El saldo de las notas vive fuera del libro: al reversarlo no devuelve disponible ni el traslado lo toma. Todo lo demás vuelve a su fuente.
            $devuelveDisponible = $m->tipo !== AdminContPaymentMovement::TIPO_HISTORICO && ! $deNota;

            $this->ledger->desaplicacion($m, $motivo, $actorId, $devuelveDisponible);

            $traslada = round(min($monto, $capacidad), 2);
            if ($traslada > 0.004) {
                $this->ledger->record([
                    'master_client_id' => $m->master_client_id, 'tipo' => $m->tipo, 'monto' => $traslada,
                    'origen' => $m->tipo === AdminContPaymentMovement::TIPO_HISTORICO ? AdminContPaymentMovement::ORIGEN_MIGRACION : AdminContPaymentMovement::ORIGEN_SISTEMA,
                    'payment_id' => $m->payment_id, 'invoice_id' => $replacement->id,
                    'delta_disponible' => $devuelveDisponible ? -$traslada : 0.0, 'delta_factura' => $traslada, 'actor_id' => $actorId, 'motivo' => $motivo,
                ]);
                $capacidad = round($capacidad - $traslada, 2);
            }

            // Lo que no cupo en el reemplazo deja de estar usado en su fuente (saldo de nota): vuelve a estar disponible.
            $liberado = round($monto - $traslada, 2);
            if ($liberado > 0.004) {
                if ($deNota && $m->compensation_id !== null) {
                    $line = AdminContCompensationLine::query()
                        ->where('compensation_id', $m->compensation_id)->where('invoice_id', $original->id)
                        ->where('source_type', AdminContCompensationLine::SOURCE_NOTA_CREDITO)->where('monto', $monto)->first();
                    if ($line !== null) {
                        $nota = AdminContCreditNote::query()->whereKey($line->source_id)->lockForUpdate()->first();
                        $nota?->update(['monto_usado' => max(0.0, round((float) $nota->monto_usado - $liberado, 2))]);
                    }
                }
            }

            if ($m->payment_id !== null) {
                $porCobro[$m->payment_id]['aplicado']    = round(($porCobro[$m->payment_id]['aplicado'] ?? 0.0) + $monto, 2);
                $porCobro[$m->payment_id]['trasladado']  = round(($porCobro[$m->payment_id]['trasladado'] ?? 0.0) + $traslada, 2);
            }
            $totalOriginal   = round($totalOriginal + $monto, 2);
            $totalTrasladado = round($totalTrasladado + $traslada, 2);
        }

        // Proyección que leen el Estado de Cuenta de los cobros anteriores, las comisiones y el recibo: una fila por (cobro, factura).
        foreach ($porCobro as $paymentId => $r) {
            $fila = AdminContPaymentInvoice::query()->where('payment_id', $paymentId)->where('invoice_id', $original->id)->lockForUpdate()->first();
            if ($fila === null) {
                continue;
            }
            $trasladado = $r['trasladado'];
            if ($trasladado > 0.004) {
                AdminContPaymentInvoice::create([
                    'payment_id' => $paymentId, 'invoice_id' => $replacement->id, 'monto_aplicado' => $trasladado,
                    'fecha_aplicacion' => $fila->fecha_aplicacion,
                ]);
            }
            $fila->delete();
        }

        $this->balance->apply($original, -$totalOriginal);
        if ($totalTrasladado > 0.004) {
            $this->balance->apply($replacement, $totalTrasladado);
        }

        return $totalTrasladado;
    }
}
