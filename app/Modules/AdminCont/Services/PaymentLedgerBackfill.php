<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F1 — copia el HISTORIAL al libro de movimientos sin tocar nada del pasado. Idempotente: no repite lo que ya tiene movimientos.
 *
 *  - Cada abono confirmado entra al disponible por su monto original y, si ya se usó algo, queda un consumo histórico: así el disponible del
 *    libro de cada cliente coincide con la suma de (monto original − monto usado) de sus abonos. El detalle de qué cobro consumió qué parte
 *    nunca se guardó (el consumo FIFO no deja rastro): por eso un cobro histórico solo se podrá reversar entero, no por componente.
 *  - Cada línea de cada cobro confirmado queda como `historico` con su aplicación a la factura, SIN efecto en el disponible (el dinero que
 *    consumió de abonos ya está reflejado arriba como consumo histórico). Así lo aplicado a cada factura según el libro coincide con lo que
 *    sumaron los cobros a `saldo_aplicado`.
 * Los cobros «esperando confirmación» no se copian: aún no aplicaron nada (se asientan al confirmarse).
 */
final class PaymentLedgerBackfill
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /** @return array{abonos: int, consumos: int, aplicaciones: int} cuántos movimientos históricos se crearon */
    public function run(): array
    {
        $creados = ['abonos' => 0, 'consumos' => 0, 'aplicaciones' => 0];

        AdminContAbono::query()->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->orderBy('id')->chunkById(500, function ($abonos) use (&$creados): void {
            foreach ($abonos as $abono) {
                /** @var AdminContAbono $abono */
                if (AdminContPaymentMovement::where('abono_id', $abono->id)->exists()) {
                    continue; // ya asentado (histórico previo, o abono nuevo con su propio ingreso/reclasificación)
                }

                DB::transaction(function () use ($abono, &$creados): void {
                    $fecha = $abono->fecha_confirmacion ?? $abono->fecha_registro ?? $abono->created_at;

                    $this->ledger->record([
                        'master_client_id' => $abono->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_HISTORICO,
                        'monto' => (float) $abono->monto_original, 'origen' => AdminContPaymentMovement::ORIGEN_MIGRACION,
                        'abono_id' => $abono->id, 'delta_disponible' => (float) $abono->monto_original,
                        'motivo' => "Abono histórico {$abono->numero_recibo}", 'fecha_movimiento' => $fecha,
                    ]);
                    $creados['abonos']++;

                    if ((float) $abono->monto_usado > 0.004) {
                        $this->ledger->record([
                            'master_client_id' => $abono->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_HISTORICO,
                            'monto' => (float) $abono->monto_usado, 'origen' => AdminContPaymentMovement::ORIGEN_MIGRACION,
                            'abono_id' => $abono->id, 'delta_disponible' => -(float) $abono->monto_usado,
                            'motivo' => "Consumo histórico del abono {$abono->numero_recibo}", 'fecha_movimiento' => $abono->updated_at ?? $fecha,
                        ]);
                        $creados['consumos']++;
                    }
                });
            }
        });

        AdminContPayment::query()->where('estado', AdminContPayment::ESTADO_CONFIRMADO)->with(['invoiceLines', 'confirmation'])->orderBy('id')
            ->chunkById(500, function ($payments) use (&$creados): void {
                foreach ($payments as $payment) {
                    /** @var AdminContPayment $payment */
                    $fecha = $payment->confirmation->confirmed_at ?? $payment->created_at;

                    foreach ($payment->invoiceLines as $line) {
                        if ((float) $line->monto_aplicado <= 0.004) {
                            continue;
                        }
                        if (AdminContPaymentMovement::where('payment_id', $payment->id)->where('invoice_id', $line->invoice_id)->exists()) {
                            continue;
                        }

                        $this->ledger->record([
                            'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_HISTORICO,
                            'monto' => (float) $line->monto_aplicado, 'origen' => AdminContPaymentMovement::ORIGEN_MIGRACION,
                            'payment_id' => $payment->id, 'invoice_id' => $line->invoice_id, 'delta_factura' => (float) $line->monto_aplicado,
                            'motivo' => "Cobro histórico {$payment->numero_recibo}", 'fecha_movimiento' => $fecha,
                        ]);
                        $creados['aplicaciones']++;
                    }
                }
            });

        return $creados;
    }
}
