<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-1168 F1 — escritura y lectura del libro de movimientos (`payment_movements`). Solo inserta; el modelo impide editar o borrar.
 *
 * Convención de signos (ver la migración): `delta_disponible` es lo que cambia el dinero disponible del cliente; `delta_factura`, lo aplicado a
 * una factura por cobros. Para un cobro:
 *   ingreso      +monto efectivo recibido        (disponible +)
 *   aplicacion   -monto aplicado a la factura    (disponible -, factura +)
 * de modo que el disponible del cliente es Σ delta_disponible y lo aplicado a una factura es Σ delta_factura.
 *
 * Fase 15a: el libro es la única fuente de verdad del dinero disponible y se escribe estricto (ya no hay escritura en sombra).
 */
final class PaymentLedger
{
    /**
     * @param array{master_client_id: int, tipo: string, monto: float, origen: string, payment_id?: ?int, invoice_id?: ?int,
     *   delta_disponible?: float, delta_factura?: float, compensation_id?: ?int, reverses_movement_id?: ?int, actor_id?: ?int,
     *   approved_by?: ?int, motivo?: ?string, fecha_movimiento?: ?CarbonInterface} $attrs
     */
    public function record(array $attrs): AdminContPaymentMovement
    {
        $attrs['monto'] = round((float) $attrs['monto'], 2);
        $attrs['fecha_movimiento'] ??= now();

        return AdminContPaymentMovement::create($attrs);
    }

    /** Dinero que entró (efectivo recibido de un cobro confirmado). */
    public function ingreso(AdminContPayment $payment, float $monto, string $origen, ?int $actorId = null): ?AdminContPaymentMovement
    {
        if ($monto <= 0.004) {
            return null;
        }

        return $this->record([
            'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_INGRESO, 'monto' => $monto,
            'origen' => $origen, 'payment_id' => $payment->id, 'delta_disponible' => $monto, 'actor_id' => $actorId,
        ]);
    }

    /** Dinero del cobro aplicado a una factura: baja el disponible del cliente y sube lo aplicado a la factura. */
    public function aplicacion(
        AdminContPayment $payment,
        AdminContInvoice $invoice,
        float $monto,
        string $origen,
        ?int $actorId = null,
        ?int $compensationId = null,
    ): ?AdminContPaymentMovement {
        if ($monto <= 0.004) {
            return null;
        }

        return $this->record([
            'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_APLICACION, 'monto' => $monto,
            'origen' => $origen, 'payment_id' => $payment->id, 'invoice_id' => $invoice->id,
            'delta_disponible' => -$monto, 'delta_factura' => $monto, 'actor_id' => $actorId, 'compensation_id' => $compensationId,
        ]);
    }

    /**
     * Compensación (cruce): dinero disponible de UNA fuente aplicado a una factura. La fuente es un cobro (`$paymentId`) o saldo de una nota de
     * crédito (sin cobro: ese dinero no está en el libro, por eso `$deltaDisponible` es 0). La factura sube por `delta_factura` en ambos casos.
     */
    public function aplicacionDeCruce(
        int $masterClientId,
        int $invoiceId,
        float $monto,
        ?int $paymentId,
        float $deltaDisponible,
        int $compensationId,
        ?int $actorId,
        ?string $motivo = null,
    ): AdminContPaymentMovement {
        return $this->record([
            'master_client_id' => $masterClientId, 'tipo' => AdminContPaymentMovement::TIPO_APLICACION, 'monto' => $monto,
            'origen' => AdminContPaymentMovement::ORIGEN_CRUCE, 'payment_id' => $paymentId, 'invoice_id' => $invoiceId,
            'delta_disponible' => $deltaDisponible, 'delta_factura' => $monto, 'compensation_id' => $compensationId, 'actor_id' => $actorId, 'motivo' => $motivo,
        ]);
    }

    /**
     * Reverso de una aplicación: otro movimiento (nunca se edita ni se borra el original) que apunta a él. El dinero vuelve al disponible de su
     * fuente y la factura recupera su saldo. `reverses_movement_id` es único: una aplicación se reversa una sola vez.
     */
    public function desaplicacion(AdminContPaymentMovement $original, string $motivo, ?int $actorId, bool $devuelveDisponible = true): AdminContPaymentMovement
    {
        return $this->record([
            'master_client_id' => $original->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_DESAPLICACION, 'monto' => (float) $original->monto,
            'origen' => $original->origen, 'payment_id' => $original->payment_id, 'invoice_id' => $original->invoice_id,
            'delta_disponible' => $devuelveDisponible ? (float) $original->monto : 0.0, 'delta_factura' => -(float) $original->monto,
            'compensation_id' => $original->compensation_id, 'reverses_movement_id' => $original->id, 'actor_id' => $actorId, 'motivo' => $motivo,
        ]);
    }

    /**
     * Reetiquetado sin dinero nuevo (efecto cero en el disponible), para dejar traza: una porción de saldo que sale de notas de crédito (que
     * siguen fuera del libro).
     * `$deltaDisponible` corrige el disponible cuando una aplicación restó dinero que el libro nunca había sumado (consumo de saldo de notas).
     */
    public function reclasificacion(int $masterClientId, float $monto, string $motivo, ?int $paymentId = null, float $deltaDisponible = 0.0): AdminContPaymentMovement
    {
        return $this->record([
            'master_client_id' => $masterClientId, 'tipo' => AdminContPaymentMovement::TIPO_RECLASIFICACION, 'monto' => max($monto, 0.01),
            'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'payment_id' => $paymentId,
            'delta_disponible' => $deltaDisponible, 'motivo' => $motivo,
        ]);
    }

    /** Dinero disponible (saldo a favor) que el libro le reconoce al cliente. */
    public function disponibleDelCliente(int $masterClientId): float
    {
        return round((float) AdminContPaymentMovement::query()->where('master_client_id', $masterClientId)->sum('delta_disponible'), 2);
    }

    /** Dinero de UN cobro del concepto nuevo que sigue sin aplicar (su saldo a favor): ingreso − aplicado − devuelto, según el libro. */
    public function remanenteDeCobro(int $paymentId): float
    {
        return round((float) AdminContPaymentMovement::query()->where('payment_id', $paymentId)->sum('delta_disponible'), 2);
    }

    /** Lo aplicado a una factura por cobros según el libro (las notas de crédito no están en el libro). */
    public function aplicadoAFactura(int $invoiceId): float
    {
        return round((float) AdminContPaymentMovement::query()->where('invoice_id', $invoiceId)->sum('delta_factura'), 2);
    }
}
