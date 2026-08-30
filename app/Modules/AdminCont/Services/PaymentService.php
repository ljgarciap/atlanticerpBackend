<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentAttachment;
use App\Modules\VentasDiseno\Models\MasterClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — registro de cobros. Batch 6
 * (SCRUM-545→548, REQ-468→471) agrega comprobante/recibo/estado real/historial/detalle. Batch 7
 * (SCRUM-549→552, REQ-472→475) agrega ver comprobante/recibo formal/apertura automática (sin
 * cambio de servicio, ver `CobrosPage.tsx`)/confirmación manual.
 */
final class PaymentService
{
    public function __construct(
        private readonly CreditBalanceService $creditBalance,
        private readonly AdminContPaymentFolioService $folio,
        private readonly AdminContPaymentAttachmentService $attachments,
        private readonly AdminContPaymentConfirmationFolioService $confirmationFolio,
    ) {}

    /**
     * REQ-462 (buscador de cliente para el formulario, sin endpoint propio de VentasDiseno —
     * este módulo no tiene `ventas_diseno.read`, así que reusa el modelo directo).
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchMasterClients(string $search): array
    {
        $query = MasterClient::query()->orderBy('name');
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return array_values($query->limit(20)->get(['id', 'name'])
            ->map(fn (MasterClient $c): array => ['id' => $c->id, 'name' => $c->name])
            ->all());
    }

    /**
     * REQ-464 RN1 — facturas con saldo pendiente > 0 del cliente, no incobrables ni anuladas.
     * Misma fórmula de saldo pendiente que `InvoiceService::agingBuckets()` (`monto - saldo_aplicado`).
     *
     * @return list<array{id: int, numero: string, monto: float, saldo_pendiente: float}>
     */
    public function openInvoicesForClient(int $masterClientId): array
    {
        $rows = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)
            ->where('es_incobrable', false)
            ->whereNull('anulada_at')
            ->get()
            ->map(fn (AdminContInvoice $i): array => [
                'id'              => $i->id,
                'numero'          => $i->numero,
                'monto'           => $i->monto,
                'saldo_pendiente' => round($i->monto - $i->saldo_aplicado, 2),
            ])
            ->all();

        return array_values(array_filter($rows, fn (array $row): bool => $row['saldo_pendiente'] > 0));
    }

    /** REQ-462 RN2 — el nombre del cliente cuando el formulario llega preseleccionado desde
     *  Facturación (query string solo trae el id, nunca el nombre). */
    public function masterClientName(int $masterClientId): ?string
    {
        return MasterClient::find($masterClientId)?->name;
    }

    /** REQ-465 — delega en la fuente única ya construida en Facturación (Batch 3). */
    public function creditBalanceForClient(int $masterClientId): float
    {
        return $this->creditBalance->forMasterClient($masterClientId);
    }

    /**
     * REQ-466 RN1/RN2/RN4/RN5 — auto-selección de cuenta bancaria destino por método. Métodos sin
     * cuenta bancaria (RN4) devuelven null directo, sin consultar nada. El match es por nombre real
     * de banco+tipo — si la cuenta esperada todavía no existe en Cuentas Bancarias (creada a mano
     * por Felix/Yaneth), devuelve null y el campo queda vacío para completar manualmente (RN3: el
     * campo sigue siendo editable, esto nunca bloquea el registro).
     */
    public function defaultBankAccountFor(string $metodoPago): ?AdminContBankAccount
    {
        if (in_array($metodoPago, AdminContPayment::METODOS_SIN_CUENTA_BANCARIA, true)) {
            return null;
        }

        if ($metodoPago === AdminContPayment::METODO_TARJETA) {
            return AdminContBankAccount::query()
                ->where('activa', true)
                ->where('banco', 'Banistmo')
                ->where('tipo_cuenta', AdminContBankAccount::TIPO_TARJETA_CREDITO)
                ->first();
        }

        return AdminContBankAccount::query()
            ->where('activa', true)
            ->where('banco', 'Banco General')
            ->where('tipo_cuenta', AdminContBankAccount::TIPO_CORRIENTE)
            ->first();
    }

    /**
     * REQ-463 — 4 tarjetas del mes calendario en curso, solo cobros `estado = confirmado` (RN1).
     *
     * @return array{total_cobrado: float, cantidad: int, promedio: float, metodo_principal: array{metodo: string, porcentaje: float}|null}
     */
    public function summaryForCurrentMonth(): array
    {
        $payments = AdminContPayment::query()
            ->where('estado', AdminContPayment::ESTADO_CONFIRMADO)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();

        $cantidad = $payments->count();
        $total    = round((float) $payments->sum('monto_recibido'), 2);

        $metodoPrincipal = null;
        if ($cantidad > 0) {
            $porMetodo = $payments->countBy('metodo_pago')->sortDesc();
            /** @var string $metodo */
            $metodo    = $porMetodo->keys()->first();
            $metodoPrincipal = [
                'metodo'     => $metodo,
                'porcentaje' => round(($porMetodo->first() / $cantidad) * 100, 1),
            ];
        }

        return [
            'total_cobrado'    => $total,
            'cantidad'         => $cantidad,
            'promedio'         => $cantidad > 0 ? round($total / $cantidad, 2) : 0.0,
            'metodo_principal' => $metodoPrincipal,
        ];
    }

    /**
     * REQ-464/465/466/467/468/469 — registra el cobro completo: descuenta saldo a favor si se
     * aplicó, asigna el folio REC-XXX (REQ-469 RN1) y el estado inicial real según método
     * (REQ-469 RN2/RN3), adjunta el comprobante si vino uno (REQ-468), y — solo si el cobro
     * arranca ya `confirmado` — reduce el saldo pendiente de cada factura seleccionada a $0 (RN2
     * de REQ-464) e inserta el movimiento en el ledger `bank_movements`. Un cobro que arranca
     * `esperando_confirmacion` deja registradas las líneas `payment_invoices` (para que el detalle,
     * REQ-471, siempre pueda mostrar qué facturas cubrió) pero NO toca `saldo_aplicado` de esas
     * facturas ni el ledger bancario todavía — RN5 de REQ-469, "no reduce el saldo pendiente de la
     * factura hasta que se confirme manualmente". Esa confirmación (botón "Marcar como confirmado",
     * REQ-475) es Batch 7 — ahí es donde se aplica lo diferido acá.
     *
     * @param list<int> $invoiceIds
     */
    public function register(
        int $masterClientId,
        array $invoiceIds,
        bool $aplicarSaldoFavor,
        string $metodoPago,
        ?int $bankAccountId,
        ?string $numeroDocumentoRetencion,
        ?string $comentarioAjuste,
        ?string $referencia,
        ?UploadedFile $comprobante,
        User $actor,
    ): AdminContPayment {
        $payment = DB::transaction(function () use (
            $masterClientId, $invoiceIds, $aplicarSaldoFavor, $metodoPago,
            $bankAccountId, $numeroDocumentoRetencion, $comentarioAjuste, $referencia, $actor,
        ): AdminContPayment {
            /** @var \Illuminate\Support\Collection<int, AdminContInvoice> $invoices */
            $invoices = AdminContInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->where('master_client_id', $masterClientId)
                ->lockForUpdate()
                ->get();

            $totalFacturas = round((float) $invoices->sum(fn (AdminContInvoice $i) => $i->monto - $i->saldo_aplicado), 2);

            $saldoAplicado = 0.0;
            if ($aplicarSaldoFavor) {
                // RN2 de REQ-465 — nunca más que el menor entre disponible y total seleccionado.
                $saldoAplicado = round(min($this->creditBalance->forMasterClient($masterClientId), $totalFacturas), 2);
            }

            $montoRecibido = round($totalFacturas - $saldoAplicado, 2);

            $estadoInicial = in_array($metodoPago, AdminContPayment::METODOS_CONFIRMACION_INMEDIATA, true)
                ? AdminContPayment::ESTADO_CONFIRMADO
                : AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION;

            $payment = AdminContPayment::create([
                'numero_recibo'               => $this->folio->next(),
                'master_client_id'           => $masterClientId,
                'monto_recibido'             => $montoRecibido,
                'saldo_favor_aplicado'       => $saldoAplicado,
                'metodo_pago'                => $metodoPago,
                'referencia'                 => $referencia,
                'bank_account_id'            => $montoRecibido > 0 ? $bankAccountId : null,
                'numero_documento_retencion' => $numeroDocumentoRetencion,
                'comentario_ajuste'          => $comentarioAjuste,
                'estado'                     => $estadoInicial,
                'created_by'                 => $actor->id,
            ]);

            foreach ($invoices as $invoice) {
                $pendiente = round($invoice->monto - $invoice->saldo_aplicado, 2);
                $payment->invoiceLines()->create(['invoice_id' => $invoice->id, 'monto_aplicado' => $pendiente]);

                if ($estadoInicial === AdminContPayment::ESTADO_CONFIRMADO) {
                    $invoice->update(['saldo_aplicado' => $invoice->saldo_aplicado + $pendiente]);
                }
            }

            if ($estadoInicial === AdminContPayment::ESTADO_CONFIRMADO) {
                if ($saldoAplicado > 0) {
                    $this->creditBalance->apply($masterClientId, $saldoAplicado);
                }

                if ($montoRecibido > 0 && $bankAccountId !== null) {
                    AdminContBankMovement::create([
                        'bank_account_id' => $bankAccountId,
                        'fecha'           => now()->toDateString(),
                        'tipo'            => AdminContBankMovement::TIPO_COBRO,
                        'concepto'        => "Cobro {$payment->numero_recibo}",
                        'monto'           => $montoRecibido,
                        'source_type'     => AdminContBankMovement::SOURCE_PAYMENT,
                        'source_id'       => $payment->id,
                    ]);
                }
            }

            return $payment;
        });

        // Subida a S3 (network) FUERA de la transacción — adentro tendría los `invoices` recién
        // lockForUpdate() todavía bloqueados durante toda la latencia de red del upload, sin
        // necesidad: el comprobante no participa de ningún cálculo de saldo/estado de este método.
        if ($comprobante !== null) {
            $this->attachments->upload($comprobante, $payment, $actor->id);
        }

        return $payment;
    }

    /**
     * REQ-470 — historial de cobros con búsqueda + filtros combinados (AND). El total general
     * (`total`) nunca se filtra, sostiene el "Mostrando X de Y" del RN3 sin otro roundtrip.
     *
     * @param array{search?: ?string, cliente?: ?string, metodo?: ?string, estado?: ?string, desde?: ?string, hasta?: ?string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function historial(array $filters): array
    {
        $total = AdminContPayment::query()->count();

        $query = AdminContPayment::query()
            ->with(['invoiceLines.invoice', 'attachment'])
            ->orderByDesc('created_at');

        if (($filters['metodo'] ?? null) !== null) {
            $query->where('metodo_pago', $filters['metodo']);
        }
        if (($filters['estado'] ?? null) !== null) {
            $query->where('estado', $filters['estado']);
        }
        if (($filters['desde'] ?? null) !== null) {
            $query->whereDate('created_at', '>=', $filters['desde']);
        }
        if (($filters['hasta'] ?? null) !== null) {
            $query->whereDate('created_at', '<=', $filters['hasta']);
        }

        $payments = $query->get();

        $clientNames = MasterClient::query()
            ->whereIn('id', $payments->pluck('master_client_id')->unique())
            ->pluck('name', 'id');

        $userNames = User::query()
            ->whereIn('id', $payments->pluck('created_by')->filter()->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $rows = $payments->map(function (AdminContPayment $p) use ($clientNames, $userNames): array {
            $cliente = $clientNames->get($p->master_client_id) ?? '';

            return [
                'id'                => $p->id,
                'numero_recibo'     => $p->numero_recibo,
                'fecha'             => $p->created_at->toIso8601String(),
                'cliente'           => $cliente,
                'facturas'          => $p->invoiceLines->map(fn ($l) => $l->invoice?->numero)->filter()->values()->all(),
                'monto_recibido'    => $p->monto_recibido,
                'metodo_pago'       => $p->metodo_pago,
                'estado'            => $p->estado,
                'referencia'        => $p->referencia,
                'registrado_por'    => $p->created_by !== null ? ($userNames->get($p->created_by) ?? '') : '',
                'tiene_comprobante' => $p->attachment !== null,
            ];
        });

        if (($filters['cliente'] ?? null) !== null) {
            $rows = $rows->filter(fn (array $r) => $r['cliente'] === $filters['cliente']);
        }

        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $rows = $rows->filter(function (array $r) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $r['cliente'], implode(' ', $r['facturas']), $r['numero_recibo'], (string) $r['referencia'],
                ]));

                return str_contains($haystack, $needle);
            });
        }

        return ['rows' => array_values($rows->all()), 'total' => $total];
    }

    /**
     * REQ-471 — detalle completo de un cobro, incluyendo las facturas que cubrió con el monto
     * aplicado a cada una (RN1). REQ-475 Escenario 2 — incluye la confirmación (si existe), con
     * quién la hizo, para la trazabilidad del detalle.
     */
    public function find(int $id): ?AdminContPayment
    {
        return AdminContPayment::query()
            ->with(['invoiceLines.invoice', 'bankAccount', 'attachment', 'confirmation.confirmedBy'])
            ->find($id);
    }

    /**
     * REQ-472 — comprobante adjunto de un cobro, con URL firmada de 15 min, o `null` si no tiene
     * ninguno (RN2 — nunca un error, un estado explícito).
     *
     * @return array{url: string, nombre_archivo: string, mime_type: string, uploaded_by: ?string, created_at: string}|null
     */
    public function attachmentDetail(AdminContPayment $payment): ?array
    {
        $attachment = $payment->attachment ?? AdminContPaymentAttachment::query()->where('payment_id', $payment->id)->first();
        if ($attachment === null) {
            return null;
        }

        $uploader = $attachment->uploader;

        return [
            'url'            => $this->attachments->presignedUrl($attachment),
            'nombre_archivo' => $attachment->nombre_archivo,
            'mime_type'      => $attachment->mime_type,
            'uploaded_by'    => $uploader !== null ? trim("{$uploader->first_name} {$uploader->last_name}") : null,
            'created_at'     => $attachment->created_at->toIso8601String(),
        ];
    }

    /**
     * REQ-475 — confirma manualmente un cobro "Esperando confirmación" (RN5, sin validación
     * bancaria automática, depende 100% de quien la presiona). Aplica acá lo que `register()`
     * difirió cuando el cobro arrancó `esperando_confirmacion` (RN3): reduce el saldo pendiente de
     * cada factura cubierta, aplica el saldo a favor si lo hubo, y deja el movimiento en el ledger
     * `bank_movements` — mismo cálculo que el camino "confirmado de inmediato" de `register()`,
     * solo que diferido hasta este momento. Genera su propio folio CONF-XXX (RN2), independiente
     * del REC-XXX, con quién confirmó y cuándo.
     *
     * @throws PaymentStateException RN1 — el cobro no existe o ya no está `esperando_confirmacion`
     *     (confirmarlo 2 veces, o confirmar uno que arrancó `confirmado`, nunca es válido).
     */
    public function confirm(int $paymentId, User $actor): AdminContPayment
    {
        return DB::transaction(function () use ($paymentId, $actor): AdminContPayment {
            /** @var AdminContPayment|null $payment */
            $payment = AdminContPayment::query()->where('id', $paymentId)->lockForUpdate()->first();

            if ($payment === null) {
                throw new PaymentStateException('Cobro no encontrado.');
            }
            if ($payment->estado !== AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION) {
                throw new PaymentStateException('Este cobro ya no está esperando confirmación.');
            }

            foreach ($payment->invoiceLines as $line) {
                /** @var AdminContInvoice|null $invoice */
                $invoice = AdminContInvoice::query()->where('id', $line->invoice_id)->lockForUpdate()->first();
                $invoice?->update(['saldo_aplicado' => $invoice->saldo_aplicado + $line->monto_aplicado]);
            }

            if ($payment->saldo_favor_aplicado > 0) {
                $this->creditBalance->apply($payment->master_client_id, $payment->saldo_favor_aplicado);
            }

            if ($payment->monto_recibido > 0 && $payment->bank_account_id !== null) {
                AdminContBankMovement::create([
                    'bank_account_id' => $payment->bank_account_id,
                    'fecha'           => now()->toDateString(),
                    'tipo'            => AdminContBankMovement::TIPO_COBRO,
                    'concepto'        => "Cobro {$payment->numero_recibo}",
                    'monto'           => $payment->monto_recibido,
                    'source_type'     => AdminContBankMovement::SOURCE_PAYMENT,
                    'source_id'       => $payment->id,
                ]);
            }

            $payment->update(['estado' => AdminContPayment::ESTADO_CONFIRMADO]);

            $payment->confirmation()->create([
                'numero_confirmacion' => $this->confirmationFolio->next(),
                'confirmed_by'        => $actor->id,
                'confirmed_at'        => now(),
            ]);

            return $payment;
        });
    }
}
