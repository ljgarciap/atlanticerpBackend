<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F4 — reversar una APLICACIÓN (desaplicar): el dinero que se había aplicado a una factura vuelve al saldo a favor de su fuente y la factura
 * recupera su saldo pendiente. No necesita aprobación (decisión de Luis, 2026-10-19: solo anular un cobro y devolver dinero pasan por Gerencia); queda en
 * el libro con motivo y autor, y la fecha es la del reverso (el movimiento original conserva la suya).
 *
 * Solo se pueden reversar aplicaciones que el libro conoce por componente: las de cobros del concepto nuevo (directas o por cruce) y las de cruces desde
 * abonos o notas de crédito. Los cobros del flujo anterior y el historial se reversan ENTEROS anulando el cobro (no hay rastro de qué abono o nota
 * consumió cada parte).
 */
final class ApplicationReversalService
{
    public function __construct(
        private readonly ClientLock $clientLock,
        private readonly InvoiceBalanceWriter $balance,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * ¿Se puede desaplicar este movimiento? Devuelve el motivo por el que no, o null si sí.
     *
     * @param array<int|string, bool> $yaReversados ids de movimientos con reverso (para no consultar uno por uno)
     */
    public function razonNoReversible(AdminContPaymentMovement $m, array $yaReversados, ?AdminContPayment $payment): ?string
    {
        if ($m->tipo !== AdminContPaymentMovement::TIPO_APLICACION) {
            return 'Solo se reversa una aplicación de dinero a una factura.';
        }
        if (isset($yaReversados[$m->id])) {
            return 'Esta aplicación ya fue reversada.';
        }
        if ($m->origen === AdminContPaymentMovement::ORIGEN_MIGRACION) {
            return 'Es historial anterior: se reversa anulando el cobro.';
        }
        if ($m->payment_id !== null && $payment !== null && $payment->estado === AdminContPayment::ESTADO_ANULADO) {
            return 'El cobro está anulado.';
        }

        return null;
    }

    /**
     * Desaplica UN movimiento de aplicación.
     *
     * @throws PaymentStateException si no se puede reversar.
     */
    public function desaplicar(int $movementId, string $motivo, User $actor): AdminContPaymentMovement
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new PaymentStateException('Indica el motivo.');
        }

        return DB::transaction(function () use ($movementId, $motivo, $actor): AdminContPaymentMovement {
            $masterId = AdminContPaymentMovement::query()->whereKey($movementId)->value('master_client_id');
            if ($masterId === null) {
                throw new PaymentStateException('Movimiento no encontrado.');
            }
            $this->clientLock->lock((int) $masterId);

            return $this->desaplicarBloqueado($movementId, $motivo, $actor);
        });
    }

    /**
     * Reversa todas las aplicaciones vigentes de un cruce y lo marca reversado.
     *
     * @throws PaymentStateException
     */
    public function reversarCompensacion(int $compensationId, string $motivo, User $actor): AdminContCompensation
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new PaymentStateException('Indica el motivo.');
        }

        return DB::transaction(function () use ($compensationId, $motivo, $actor): AdminContCompensation {
            /** @var AdminContCompensation|null $compensation */
            $compensation = AdminContCompensation::query()->whereKey($compensationId)->first();
            if ($compensation === null) {
                throw new PaymentStateException('Compensación no encontrada.');
            }
            $this->clientLock->lock($compensation->master_client_id);

            /** @var AdminContCompensation $compensation */
            $compensation = AdminContCompensation::query()->whereKey($compensationId)->lockForUpdate()->firstOrFail();
            if ($compensation->estado === AdminContCompensation::ESTADO_REVERSADO) {
                throw new PaymentStateException('Esta compensación ya fue reversada.');
            }

            $reversados = AdminContPaymentMovement::query()->whereNotNull('reverses_movement_id')->pluck('reverses_movement_id')->flip()->map(fn () => true)->all();
            $pendientes = AdminContPaymentMovement::query()
                ->where('compensation_id', $compensationId)->where('tipo', AdminContPaymentMovement::TIPO_APLICACION)->orderBy('id')->get()
                ->filter(fn (AdminContPaymentMovement $m): bool => ! isset($reversados[$m->id]));

            foreach ($pendientes as $m) {
                $this->desaplicarBloqueado($m->id, $motivo, $actor);
            }

            // `desaplicarBloqueado` ya marca el cruce como reversado cuando se reversó su última línea; si no tenía líneas vigentes, se marca aquí.
            $compensation->refresh();
            if ($compensation->estado !== AdminContCompensation::ESTADO_REVERSADO) {
                $compensation->update(['estado' => AdminContCompensation::ESTADO_REVERSADO, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'motivo_reverso' => $motivo]);
            }

            return $compensation->load('lines');
        });
    }

    /**
     * SCRUM-1168 F4 — al ANULAR un cobro del concepto nuevo se reversan todas sus aplicaciones vigentes (directas o por cruce). Debe llamarse dentro de una
     * transacción con el cliente ya bloqueado y ANTES de marcar el cobro como anulado.
     *
     * @return int cuántas aplicaciones se reversaron
     */
    public function desaplicarTodoDeCobro(AdminContPayment $payment, string $motivo, User $actor): int
    {
        $reversados = AdminContPaymentMovement::query()->whereNotNull('reverses_movement_id')->pluck('reverses_movement_id')->flip()->map(fn () => true)->all();
        $pendientes = AdminContPaymentMovement::query()
            ->where('payment_id', $payment->id)->where('tipo', AdminContPaymentMovement::TIPO_APLICACION)->orderBy('id')->get()
            ->filter(fn (AdminContPaymentMovement $m): bool => ! isset($reversados[$m->id]));

        foreach ($pendientes as $m) {
            $this->desaplicarBloqueado($m->id, $motivo, $actor);
        }

        return $pendientes->count();
    }

    /** Debe llamarse dentro de una transacción con el cliente ya bloqueado. */
    private function desaplicarBloqueado(int $movementId, string $motivo, User $actor): AdminContPaymentMovement
    {
        /** @var AdminContPaymentMovement|null $m */
        // Sin lockForUpdate: el libro es append-only (el rol de la app no tiene UPDATE) y FOR UPDATE exige ese privilegio; el cliente ya está bloqueado.
        $m = AdminContPaymentMovement::query()->whereKey($movementId)->first();
        if ($m === null) {
            throw new PaymentStateException('Movimiento no encontrado.');
        }

        $payment  = $m->payment_id !== null ? AdminContPayment::query()->whereKey($m->payment_id)->lockForUpdate()->first() : null;
        $reversado = AdminContPaymentMovement::query()->where('reverses_movement_id', $m->id)->exists();
        $razon    = $this->razonNoReversible($m, $reversado ? [$m->id => true] : [], $payment);
        if ($razon !== null) {
            throw new PaymentStateException($razon);
        }

        /** @var AdminContInvoice|null $invoice */
        $invoice = AdminContInvoice::query()->whereKey($m->invoice_id)->lockForUpdate()->first();
        if ($invoice === null) {
            throw new PaymentStateException('La factura de esta aplicación ya no existe.');
        }

        $monto = round((float) $m->monto, 2);

        // Fuente del dinero: cobro (proyección payment_invoices + libro) o saldo de una nota (monto_usado de la nota).
        $devuelveDisponible = true;
        if ($payment !== null) {
            $row = AdminContPaymentInvoice::query()->where('payment_id', $payment->id)->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($row !== null) {
                $resto = round((float) $row->monto_aplicado - $monto, 2);
                if ($resto <= 0.004) {
                    $row->delete();
                } else {
                    $row->update(['monto_aplicado' => $resto]);
                }
            }
        } else {
            $line = AdminContCompensationLine::query()
                ->where('compensation_id', $m->compensation_id)->where('invoice_id', $m->invoice_id)
                ->where('source_type', AdminContCompensationLine::SOURCE_NOTA_CREDITO)->where('monto', $monto)->first();
            if ($line === null) {
                throw new PaymentStateException('No se pudo determinar la nota de crédito de origen de esta aplicación.');
            }
            /** @var AdminContCreditNote $nota */
            $nota = AdminContCreditNote::query()->whereKey($line->source_id)->lockForUpdate()->firstOrFail();
            $nota->update(['monto_usado' => max(0.0, round((float) $nota->monto_usado - $monto, 2))]);
            $devuelveDisponible = false;   // el saldo de las notas vive fuera del libro
        }

        $this->balance->apply($invoice, -$monto);
        $reverso = $this->ledger->desaplicacion($m, $motivo, $actor->id, $devuelveDisponible);

        // Si era la última aplicación vigente de un cruce, el cruce queda reversado.
        if ($m->compensation_id !== null) {
            $vigentes = AdminContPaymentMovement::query()
                ->where('compensation_id', $m->compensation_id)->where('tipo', AdminContPaymentMovement::TIPO_APLICACION)
                ->whereNotIn('id', AdminContPaymentMovement::query()->whereNotNull('reverses_movement_id')->select('reverses_movement_id'))
                ->count();
            if ($vigentes === 0) {
                AdminContCompensation::query()->whereKey($m->compensation_id)->update([
                    'estado' => AdminContCompensation::ESTADO_REVERSADO, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'motivo_reverso' => $motivo,
                ]);
            }
        }

        return $reverso;
    }
}
