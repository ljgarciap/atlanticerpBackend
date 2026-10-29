<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Models\AdminContPaymentReversalRequest;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-1168 F4 — anular un cobro (recibo mal hecho). Contabilidad o Asistente Administrativa lo SOLICITAN con motivo; Gerencia lo APRUEBA o RECHAZA (decisión de Luis, 2026-10-19);
 * al aprobar se ejecuta, todo en una transacción bajo el bloqueo del cliente. Nada se borra: el cobro queda en el historial con estado `anulado` y todos
 * sus efectos se reversan con contra-movimientos (libro, facturas, caja). Reglas ya decididas:
 *   - la fecha del reverso es la del día en que se anula; el movimiento original conserva la suya;
 *   - en caja el reverso entra como salida del día de la anulación (arqueo de ese día); un arqueo cerrado no se toca;
 *   - si reversar deja sin cobrar un pedido cuya comisión ya estaba liquidada, la comisión pagada no se toca: se registra un descuento para la siguiente
 *     liquidación del vendedor (`CommissionAdjustmentService`).
 */
final class PaymentReversalService
{
    public function __construct(
        private readonly ClientLock $clientLock,
        private readonly InvoiceBalanceWriter $balance,
        private readonly PaymentLedger $ledger,
        private readonly ApplicationReversalService $applications,
        private readonly CommissionAdjustmentService $commissionAdjustments,
        private readonly AbonoService $abonos,
        private readonly CompensationService $compensations,
    ) {}

    /** @throws PaymentStateException si el cobro no se puede anular. */
    public function solicitarAnulacion(int $paymentId, string $motivo, User $actor): AdminContPaymentReversalRequest
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new PaymentStateException('Indica el motivo de la anulación.');
        }

        /** @var AdminContPayment|null $payment */
        $payment = AdminContPayment::query()->find($paymentId);
        if ($payment === null) {
            throw new PaymentStateException('Cobro no encontrado.');
        }
        $this->validarAnulable($payment);

        $abierta = AdminContPaymentReversalRequest::query()
            ->where('tipo', AdminContPaymentReversalRequest::TIPO_ANULACION)->where('payment_id', $paymentId)
            ->where('estado', AdminContPaymentReversalRequest::ESTADO_SOLICITADA)->exists();
        if ($abierta) {
            throw new PaymentStateException('Este cobro ya tiene una solicitud de anulación pendiente de aprobación.');
        }

        return AdminContPaymentReversalRequest::create([
            'tipo' => AdminContPaymentReversalRequest::TIPO_ANULACION, 'master_client_id' => $payment->master_client_id, 'payment_id' => $payment->id,
            'monto' => $payment->monto_recibido, 'motivo' => $motivo, 'estado' => AdminContPaymentReversalRequest::ESTADO_SOLICITADA, 'requested_by' => $actor->id,
        ]);
    }

    /**
     * Fase 13 (2.3, decisión de Luis) — reversar una compensación reabre la deuda de las facturas igual que anular un cobro, así que pasa por el
     * mismo control: se solicita con motivo y lo decide el aprobador (o un suplente). Mientras esté pendiente, la compensación sigue vigente.
     *
     * @throws PaymentStateException si la compensación no existe, ya fue reversada o ya tiene una solicitud pendiente.
     */
    public function solicitarReversoCompensacion(int $compensationId, string $motivo, User $actor): AdminContPaymentReversalRequest
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new PaymentStateException('Indica el motivo del reverso.');
        }

        /** @var AdminContCompensation|null $compensation */
        $compensation = AdminContCompensation::query()->find($compensationId);
        if ($compensation === null) {
            throw new PaymentStateException('Compensación no encontrada.');
        }
        if ($compensation->estado === AdminContCompensation::ESTADO_REVERSADO) {
            throw new PaymentStateException('Esta compensación ya fue reversada.');
        }

        $abierta = AdminContPaymentReversalRequest::query()
            ->where('tipo', AdminContPaymentReversalRequest::TIPO_REVERSO_COMPENSACION)->where('source_id', $compensationId)
            ->where('estado', AdminContPaymentReversalRequest::ESTADO_SOLICITADA)->exists();
        if ($abierta) {
            throw new PaymentStateException('Esta compensación ya tiene una solicitud de reverso pendiente de aprobación.');
        }

        return AdminContPaymentReversalRequest::create([
            'tipo' => AdminContPaymentReversalRequest::TIPO_REVERSO_COMPENSACION, 'master_client_id' => $compensation->master_client_id,
            'source_type' => AdminContPaymentReversalRequest::SOURCE_COMPENSACION, 'source_id' => $compensation->id,
            'monto' => $compensation->monto_total, 'motivo' => $motivo, 'estado' => AdminContPaymentReversalRequest::ESTADO_SOLICITADA, 'requested_by' => $actor->id,
        ]);
    }

    /**
     * Decide una solicitud (Gerencia): rechazarla la deja asentada; aprobarla la ejecuta.
     *
     * @return array{request: AdminContPaymentReversalRequest, ajustes_comision: list<array{vendedor_id: int, pedido_id: int, monto: float}>, sugerencia_compensacion: array<string, mixed>|null}
     * @throws PaymentStateException
     */
    public function decidir(int $requestId, bool $aprobar, ?string $motivoRechazo, User $mark): array
    {
        return DB::transaction(function () use ($requestId, $aprobar, $motivoRechazo, $mark): array {
            /** @var AdminContPaymentReversalRequest|null $request */
            $request = AdminContPaymentReversalRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new PaymentStateException('Solicitud no encontrada.');
            }
            if ($request->estado !== AdminContPaymentReversalRequest::ESTADO_SOLICITADA) {
                throw new PaymentStateException('Esta solicitud ya fue resuelta.');
            }

            if (! $aprobar) {
                if (trim((string) $motivoRechazo) === '') {
                    throw new PaymentStateException('Indica el motivo del rechazo.');
                }
                $request->update([
                    'estado' => AdminContPaymentReversalRequest::ESTADO_RECHAZADA, 'decided_by' => $mark->id, 'decided_at' => now(), 'motivo_rechazo' => trim((string) $motivoRechazo),
                ]);

                return ['request' => $request, 'ajustes_comision' => [], 'sugerencia_compensacion' => null];
            }

            if ($request->tipo === AdminContPaymentReversalRequest::TIPO_REVERSO_COMPENSACION) {
                // El que ejecuta el reverso es quien lo aprobó: queda como `reversed_by` de la compensación y en el libro con el motivo solicitado.
                $this->applications->reversarCompensacion((int) $request->source_id, (string) $request->motivo, $mark);
                $ajustes = [];
            } else {
                $ajustes = $request->tipo === AdminContPaymentReversalRequest::TIPO_ANULACION
                    ? $this->ejecutarAnulacion($request, $mark)
                    : $this->ejecutarDevolucion($request, $mark);
            }

            $request->update(['estado' => AdminContPaymentReversalRequest::ESTADO_EJECUTADA, 'decided_by' => $mark->id, 'decided_at' => now(), 'executed_at' => now()]);

            // Fase 13 (2.4): al anular un cobro las facturas se reabren por su saldo completo aunque el cliente tenga saldo a favor para cubrirlas
            // (p. ej. de notas de crédito); se SUGIERE la compensación, no se aplica sola.
            $sugerencia = $request->tipo === AdminContPaymentReversalRequest::TIPO_ANULACION ? $this->sugerirCompensacion($request) : null;

            return ['request' => $request, 'ajustes_comision' => $ajustes, 'sugerencia_compensacion' => $sugerencia];
        });
    }

    /**
     * Fase 13 (2.4) — tras anular un cobro: las facturas que ese cobro pagaba y siguen con saldo pendiente, el saldo a favor que el cliente tiene y
     * cuánto de eso se podría compensar. `null` si no hay nada que sugerir. Solo informa; la compensación la hace una persona desde Compensaciones.
     *
     * @return array{master_client_id: int, saldo_a_favor: float, pendiente_total: float, compensable: float, facturas: list<array{invoice_id: int, numero: string|null, pendiente: float}>}|null
     */
    private function sugerirCompensacion(AdminContPaymentReversalRequest $request): ?array
    {
        // Las facturas que pagaba el cobro: líneas del flujo anterior y aplicaciones del libro del concepto nuevo (las líneas pueden desaparecer al desaplicar).
        $invoiceIds = AdminContPaymentInvoice::query()->where('payment_id', $request->payment_id)->pluck('invoice_id')
            ->merge(AdminContPaymentMovement::query()->where('payment_id', $request->payment_id)->where('tipo', AdminContPaymentMovement::TIPO_APLICACION)
                ->whereNotNull('invoice_id')->pluck('invoice_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($invoiceIds === []) {
            return null;
        }

        $facturas = [];
        foreach (AdminContInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->get() as $invoice) {
            $pendiente = round((float) $invoice->monto - (float) $invoice->saldo_aplicado, 2);
            if ($pendiente > 0.004) {
                $facturas[] = ['invoice_id' => (int) $invoice->id, 'numero' => $invoice->numero, 'pendiente' => $pendiente];
            }
        }

        $saldo = $this->abonos->desgloseSaldoCliente($request->master_client_id)['total'];
        $pendienteTotal = round(array_sum(array_column($facturas, 'pendiente')), 2);
        if ($facturas === [] || $saldo <= 0.004) {
            return null;
        }

        return [
            'master_client_id' => (int) $request->master_client_id, 'saldo_a_favor' => $saldo, 'pendiente_total' => $pendienteTotal,
            'compensable' => round(min($saldo, $pendienteTotal), 2), 'facturas' => $facturas,
        ];
    }

    private function validarAnulable(AdminContPayment $payment): void
    {
        if ($payment->estado === AdminContPayment::ESTADO_ANULADO) {
            throw new PaymentStateException('Este cobro ya está anulado.');
        }
        $devuelto = AdminContPaymentMovement::query()->where('payment_id', $payment->id)->where('tipo', AdminContPaymentMovement::TIPO_DEVOLUCION)->exists();
        if ($devuelto) {
            throw new PaymentStateException('Este cobro tiene devoluciones de dinero al cliente: no se puede anular.');
        }
    }

    /**
     * Anula el cobro. Esperando confirmación: nunca aplicó nada ni entró a caja, solo se marca. Confirmado del concepto nuevo: reversa sus aplicaciones y retira
     * lo que seguía sin aplicar. Confirmado del flujo anterior: reversa cada línea y devuelve al cliente el saldo que había consumido.
     *
     * @return list<array{vendedor_id: int, pedido_id: int, monto: float}> descuentos de comisión registrados
     */
    private function ejecutarAnulacion(AdminContPaymentReversalRequest $request, User $mark): array
    {
        /** @var AdminContPayment|null $first */
        $first = AdminContPayment::query()->whereKey($request->payment_id)->first();
        if ($first === null) {
            throw new PaymentStateException('Cobro no encontrado.');
        }
        $this->clientLock->lock($first->master_client_id);

        /** @var AdminContPayment $payment */
        $payment = AdminContPayment::query()->whereKey($request->payment_id)->lockForUpdate()->firstOrFail();
        $this->validarAnulable($payment);
        $motivo = "Anulación del cobro {$payment->numero_recibo}: {$request->motivo}";

        $ajustes = [];
        if ($payment->estado === AdminContPayment::ESTADO_CONFIRMADO) {
            $invoiceIds = array_values(array_map('intval', $payment->invoiceLines->pluck('invoice_id')->all()));
            $previo = $this->commissionAdjustments->estadoPrevio($invoiceIds);

            // Bloqueo de las facturas del cobro, siempre por id.
            $invoices = AdminContInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($payment->cobros_v2) {
                $this->anularV2($payment, $motivo, $mark);
            } else {
                $this->anularFlujoAnterior($payment, $invoices, $motivo, $mark);
            }

            $this->revertirCaja($payment);
            $ajustes = $this->commissionAdjustments->registrarDespues($previo, $motivo, null);
        }

        $payment->update([
            'estado' => AdminContPayment::ESTADO_ANULADO, 'anulado_at' => now(), 'anulado_by' => $mark->id, 'motivo_anulacion' => $request->motivo,
        ]);

        return $ajustes;
    }

    private function anularV2(AdminContPayment $payment, string $motivo, User $mark): void
    {
        $this->applications->desaplicarTodoDeCobro($payment, $motivo, $mark);

        // Lo que seguía sin aplicar (su saldo a favor) deja de existir: el dinero se retira.
        $remanente = $this->ledger->remanenteDeCobro($payment->id);
        if ($remanente > 0.004) {
            $this->ledger->record([
                'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_ANULACION, 'monto' => $remanente,
                'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'payment_id' => $payment->id, 'delta_disponible' => -$remanente,
                'actor_id' => $mark->id, 'motivo' => $motivo,
            ]);
        }
    }

    /**
     * Cobro del flujo anterior: cada línea baja su factura, el abono obligatorio consumido se restituye y el saldo de nivel cliente que había usado vuelve al
     * cliente como un abono nuevo. Los contra-movimientos del libro espejan exactamente lo que el cobro asentó (ver `PaymentService::registrarEnLibro`).
     *
     * @param \Illuminate\Support\Collection<int, AdminContInvoice> $invoices
     */
    private function anularFlujoAnterior(AdminContPayment $payment, \Illuminate\Support\Collection $invoices, string $motivo, User $mark): void
    {
        foreach ($payment->invoiceLines as $line) {
            $monto = round((float) $line->monto_aplicado, 2);
            if ($monto <= 0.004) {
                continue;
            }
            $invoice = $invoices->get($line->invoice_id);
            if ($invoice !== null) {
                $this->balance->apply($invoice, -$monto);
            }

            // Abono obligatorio de nivel factura/proyecto que este cobro consumió: vuelve a su saldo.
            if ($line->abono_id !== null && (float) $line->monto_abono_aplicado > 0.004) {
                /** @var AdminContAbono|null $abono */
                $abono = AdminContAbono::query()->whereKey($line->abono_id)->lockForUpdate()->first();
                $abono?->update(['monto_usado' => max(0.0, round((float) $abono->monto_usado - (float) $line->monto_abono_aplicado, 2))]);
            }

            $this->ledger->shadow(fn () => $this->ledger->record([
                'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_ANULACION, 'monto' => $monto,
                'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'payment_id' => $payment->id, 'invoice_id' => $line->invoice_id,
                'delta_disponible' => $monto, 'delta_factura' => -$monto, 'actor_id' => $mark->id, 'motivo' => $motivo,
            ]));
        }

        if ((float) $payment->saldo_favor_aplicado > 0.004) {
            $this->abonos->registrarRestitucionPorAnulacion($payment, (float) $payment->saldo_favor_aplicado);
        }

        if ((float) $payment->monto_recibido > 0.004) {
            $this->ledger->shadow(fn () => $this->ledger->record([
                'master_client_id' => $payment->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_ANULACION, 'monto' => (float) $payment->monto_recibido,
                'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'payment_id' => $payment->id, 'delta_disponible' => -(float) $payment->monto_recibido,
                'actor_id' => $mark->id, 'motivo' => $motivo,
            ]));
        }
    }

    /** El dinero que había entrado a una cuenta sale en la fecha de la anulación (arqueo y posición de caja de ese día). */
    private function revertirCaja(AdminContPayment $payment): void
    {
        if ((float) $payment->monto_recibido <= 0.004 || $payment->bank_account_id === null) {
            return;
        }

        AdminContBankMovement::create([
            'bank_account_id' => $payment->bank_account_id,
            'fecha'           => now()->toDateString(),
            'tipo'            => AdminContBankMovement::TIPO_DEVOLUCION,
            'concepto'        => "Anulación del cobro {$payment->numero_recibo}",
            'monto'           => (float) $payment->monto_recibido,
            'source_type'     => AdminContBankMovement::SOURCE_PAYMENT_REVERSAL,
            'source_id'       => $payment->id,
        ]);
    }

    private const DISK = 's3-private';

    /**
     * Devolución de dinero al cliente (F5): Contabilidad o Asistente Administrativa SOLICITAN sacar plata del saldo a favor del cliente (de una fuente concreta: un cobro sin
     * aplicar, un abono o una nota de crédito) y Gerencia la aprueba. Exige cuenta o caja de salida, referencia y comprobante; el motivo queda en la solicitud.
     * No se puede pedir más de lo disponible de la fuente (descontando otras solicitudes pendientes sobre la misma).
     *
     * @throws PaymentStateException
     */
    public function solicitarDevolucion(
        int $masterClientId, string $sourceType, int $sourceId, float $monto, int $bankAccountId, string $referencia, UploadedFile $comprobante, string $motivo, User $actor,
    ): AdminContPaymentReversalRequest {
        $monto = round($monto, 2);
        if ($monto <= 0.004) {
            throw new PaymentStateException('El monto a devolver debe ser mayor a cero.');
        }
        if (trim($motivo) === '' || trim($referencia) === '') {
            throw new PaymentStateException('Indica el motivo y la referencia de la devolución.');
        }
        $cuenta = AdminContBankAccount::query()->find($bankAccountId);
        if ($cuenta === null || ! $cuenta->activa) {
            throw new PaymentStateException('Elige una cuenta o caja de salida activa.');
        }

        $disponible = $this->disponibleDeFuente($masterClientId, $sourceType, $sourceId);
        $pendiente  = (float) AdminContPaymentReversalRequest::query()
            ->where('tipo', AdminContPaymentReversalRequest::TIPO_DEVOLUCION)->where('estado', AdminContPaymentReversalRequest::ESTADO_SOLICITADA)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->sum('monto');
        if ($monto > round($disponible - $pendiente, 2) + 0.005) {
            throw new PaymentStateException('La fuente elegida no tiene tanto saldo disponible (disponible $'.number_format(max(0.0, $disponible - $pendiente), 2).', contando solicitudes pendientes).');
        }

        $ext = strtolower($comprobante->getClientOriginalExtension());
        $key = 'admin-contab/client-refunds/'.Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');
        (new CircuitBreaker(self::DISK))->call(function () use ($comprobante, $key): void {
            if (Storage::disk(self::DISK)->putFileAs(dirname($key), $comprobante, basename($key)) === false) {
                throw new \RuntimeException("S3 upload failed for key [{$key}]");
            }
        });

        return AdminContPaymentReversalRequest::create([
            'tipo' => AdminContPaymentReversalRequest::TIPO_DEVOLUCION, 'master_client_id' => $masterClientId, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'monto' => $monto, 'bank_account_id' => $bankAccountId, 'referencia' => trim($referencia), 'comprobante_key' => $key, 'motivo' => trim($motivo),
            'estado' => AdminContPaymentReversalRequest::ESTADO_SOLICITADA, 'requested_by' => $actor->id,
        ]);
    }

    /** URL firmada de 15 min del comprobante de una devolución, o null. */
    public function comprobanteUrl(AdminContPaymentReversalRequest $r): ?string
    {
        return $r->comprobante_key === null ? null : Storage::disk(self::DISK)->temporaryUrl($r->comprobante_key, now()->addMinutes(15));
    }

    private function disponibleDeFuente(int $masterClientId, string $sourceType, int $sourceId): float
    {
        foreach ($this->compensations->fuentes($masterClientId) as $f) {
            if ($f['source_type'] === $sourceType && $f['source_id'] === $sourceId) {
                return (float) $f['disponible'];
            }
        }
        throw new PaymentStateException('La fuente elegida no existe o no tiene saldo disponible para este cliente.');
    }

    /**
     * Ejecuta la devolución aprobada: descuenta del saldo a favor de la fuente (revalidando bajo el bloqueo del cliente), deja el movimiento en el libro y
     * registra la salida en la cuenta/caja elegida con la fecha de hoy (arqueo del día). Una devolución no cambia facturas ni comisiones.
     *
     * @return list<array{vendedor_id: int, pedido_id: int, monto: float}>
     */
    private function ejecutarDevolucion(AdminContPaymentReversalRequest $request, User $mark): array
    {
        $this->clientLock->lock($request->master_client_id);

        $monto = round((float) $request->monto, 2);
        $type  = (string) $request->source_type;
        $id    = (int) $request->source_id;
        if ($monto > $this->disponibleDeFuente($request->master_client_id, $type, $id) + 0.005) {
            throw new PaymentStateException('La fuente ya no tiene saldo suficiente para esta devolución.');
        }

        $attrs = [
            'master_client_id' => $request->master_client_id, 'tipo' => AdminContPaymentMovement::TIPO_DEVOLUCION, 'monto' => $monto,
            'origen' => AdminContPaymentMovement::ORIGEN_SISTEMA, 'delta_disponible' => -$monto, 'actor_id' => $mark->id, 'approved_by' => $mark->id, 'motivo' => $request->motivo,
        ];
        if ($type === AdminContCompensationLine::SOURCE_COBRO) {
            $attrs['payment_id'] = $id;
        } elseif ($type === AdminContCompensationLine::SOURCE_ABONO) {
            /** @var AdminContAbono $abono */
            $abono = AdminContAbono::query()->lockForUpdate()->findOrFail($id);
            $abono->update(['monto_usado' => round((float) $abono->monto_usado + $monto, 2)]);
            $attrs['abono_id'] = $id;
        } else {
            // Las notas de crédito siguen fuera del libro: se descuenta su saldo y el movimiento queda solo como traza (no mueve el disponible del libro).
            /** @var AdminContCreditNote $nota */
            $nota = AdminContCreditNote::query()->lockForUpdate()->findOrFail($id);
            $nota->update(['monto_usado' => round((float) $nota->monto_usado + $monto, 2)]);
            $attrs['delta_disponible'] = 0.0;
        }
        $this->ledger->record($attrs);

        AdminContBankMovement::create([
            'bank_account_id' => $request->bank_account_id, 'fecha' => now()->toDateString(), 'tipo' => AdminContBankMovement::TIPO_DEVOLUCION,
            'concepto' => 'Devolución al cliente · ref. '.$request->referencia, 'monto' => $monto,
            'source_type' => AdminContBankMovement::SOURCE_CLIENT_REFUND, 'source_id' => $request->id,
        ]);

        return [];
    }
}
