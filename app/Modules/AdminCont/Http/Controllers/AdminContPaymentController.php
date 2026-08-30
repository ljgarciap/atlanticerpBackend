<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Http\Requests\StorePaymentRequest;
use App\Modules\AdminCont\Http\Requests\UploadPaymentRetentionAttachmentRequest;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Services\AdminContPaymentRetentionAttachmentService;
use App\Modules\AdminCont\Services\PaymentReceiptPdfService;
use App\Modules\AdminCont\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — Cobros. Batch 6 (SCRUM-545→548,
 * REQ-468→471) agrega comprobante/recibo+estado real/historial (`index`)/detalle (`show`). Batch 7
 * (SCRUM-549→552, REQ-472→475) agrega `attachment` (ver comprobante), `pdf` (recibo formal) y
 * `confirm` (confirmación manual bancaria) — REQ-474 (apertura automática desde Facturación) no
 * necesita endpoint propio, ya la resuelve `CobrosPage.tsx` con los mismos parámetros de query
 * string que REQ-462 RN2 (Batch 5).
 */
class AdminContPaymentController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentReceiptPdfService $receiptPdf,
        private readonly AdminContPaymentRetentionAttachmentService $retentionAttachments,
    ) {}

    /** GET /api/admin-contab/payments/summary — REQ-463, 4 tarjetas del mes en curso. */
    public function summary(): JsonResponse
    {
        return response()->json($this->payments->summaryForCurrentMonth());
    }

    /** GET /api/admin-contab/payments/clients?search= — REQ-462, buscador de cliente del formulario. */
    public function clients(Request $request): JsonResponse
    {
        $search = (string) $request->query('search', '');

        return response()->json(['data' => $this->payments->searchMasterClients($search)]);
    }

    /** GET /api/admin-contab/payments/open-invoices?master_client_id= — REQ-464 RN1. */
    public function openInvoices(Request $request): JsonResponse
    {
        $request->validate(['master_client_id' => ['required', 'integer']]);
        $masterClientId = (int) $request->integer('master_client_id');

        return response()->json([
            'invoices'          => $this->payments->openInvoicesForClient($masterClientId),
            'credit_balance'    => $this->payments->creditBalanceForClient($masterClientId),
            'master_client_name' => $this->payments->masterClientName($masterClientId),
        ]);
    }

    /** GET /api/admin-contab/payments/default-bank-account?metodo_pago= — REQ-466. */
    public function defaultBankAccount(Request $request): JsonResponse
    {
        $request->validate(['metodo_pago' => ['required', 'string']]);
        $account = $this->payments->defaultBankAccountFor((string) $request->query('metodo_pago'));

        return response()->json($account === null ? ['data' => null] : [
            'data' => ['id' => $account->id, 'label' => $account->label()],
        ]);
    }

    /** GET /api/admin-contab/payments — REQ-470, historial con búsqueda + filtros. */
    public function index(Request $request): JsonResponse
    {
        $result = $this->payments->historial([
            'search'  => $request->filled('search') ? $request->string('search')->toString() : null,
            'cliente' => $request->filled('cliente') ? $request->string('cliente')->toString() : null,
            'metodo'  => $request->filled('metodo') ? $request->string('metodo')->toString() : null,
            'estado'  => $request->filled('estado') ? $request->string('estado')->toString() : null,
            'desde'   => $request->filled('desde') ? $request->string('desde')->toString() : null,
            'hasta'   => $request->filled('hasta') ? $request->string('hasta')->toString() : null,
        ]);

        return response()->json($result);
    }

    /** GET /api/admin-contab/payments/{id} — REQ-471, detalle completo de un cobro. */
    public function show(int $id): JsonResponse
    {
        $payment = $this->payments->find($id);
        if ($payment === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->presentDetail($payment));
    }

    /** POST /api/admin-contab/payments — REQ-464/465/466/467/468/469, registra el cobro completo. */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $payment = $this->payments->register(
            masterClientId: (int) $request->validated('master_client_id'),
            invoiceIds: $request->validated('invoice_ids'),
            aplicarSaldoFavor: $request->aplicarSaldoFavor(),
            metodoPago: (string) $request->validated('metodo_pago'),
            bankAccountId: $request->validated('bank_account_id') !== null ? (int) $request->validated('bank_account_id') : null,
            numeroDocumentoRetencion: $request->validated('numero_documento_retencion'),
            comentarioAjuste: $request->validated('comentario_ajuste'),
            referencia: $request->validated('referencia'),
            comprobante: $request->file('comprobante'),
            actor: $actor,
        );

        return response()->json($this->present($payment), 201);
    }

    /** @return array<string, mixed> */
    private function present(AdminContPayment $payment): array
    {
        return [
            'id'                          => $payment->id,
            'numero_recibo'               => $payment->numero_recibo,
            'master_client_id'            => $payment->master_client_id,
            'monto_recibido'              => $payment->monto_recibido,
            'saldo_favor_aplicado'        => $payment->saldo_favor_aplicado,
            'metodo_pago'                 => $payment->metodo_pago,
            'referencia'                  => $payment->referencia,
            'bank_account_id'             => $payment->bank_account_id,
            'numero_documento_retencion'  => $payment->numero_documento_retencion,
            'comentario_ajuste'           => $payment->comentario_ajuste,
            'estado'                      => $payment->estado,
            'created_at'                  => $payment->created_at->toIso8601String(),
        ];
    }

    /**
     * REQ-471 RN1/RN2 — detalle completo, con las facturas cubiertas y sus montos aplicados.
     * REQ-473 RN1 — `cliente`/`metodo_pago_label`/`total_facturas` agregados en Batch 7, los usa
     * también el recibo formal (`pdf()`). REQ-475 Escenario 2 — `confirmacion`, cuando existe.
     * @return array<string, mixed>
     */
    private function presentDetail(AdminContPayment $payment): array
    {
        $creator     = $payment->created_by !== null ? User::find($payment->created_by) : null;
        $confirmacion = $payment->confirmation;

        return [
            ...$this->present($payment),
            'cliente'           => $this->payments->masterClientName($payment->master_client_id),
            'metodo_pago_label' => AdminContPayment::METODO_LABELS[$payment->metodo_pago] ?? $payment->metodo_pago,
            'registrado_por'    => $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null,
            'bank_account'      => $payment->bankAccount?->label(),
            'tiene_comprobante' => $payment->attachment !== null,
            // RN1 REQ-473 — total antes de aplicar el saldo a favor, para el recibo formal.
            'total_facturas'    => round($payment->monto_recibido + $payment->saldo_favor_aplicado, 2),
            'facturas'          => $payment->invoiceLines->map(fn ($l) => [
                'invoice_id'     => $l->invoice_id,
                'numero'         => $l->invoice?->numero,
                'monto_aplicado' => $l->monto_aplicado,
            ])->values()->all(),
            'confirmacion' => $confirmacion !== null ? [
                'numero_confirmacion' => $confirmacion->numero_confirmacion,
                'confirmado_por'      => $confirmacion->confirmedBy !== null
                    ? trim("{$confirmacion->confirmedBy->first_name} {$confirmacion->confirmedBy->last_name}")
                    : null,
                'confirmado_at' => $confirmacion->confirmed_at->toIso8601String(),
            ] : null,
        ];
    }

    /** GET /api/admin-contab/payments/{id}/attachment — REQ-472, comprobante adjunto o `null`. */
    public function attachment(int $id): JsonResponse
    {
        $payment = $this->payments->find($id);
        if ($payment === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->payments->attachmentDetail($payment)]);
    }

    /** GET /api/admin-contab/payments/{id}/pdf — REQ-473, recibo formal. */
    public function pdf(int $id): \Illuminate\Http\Response
    {
        $payment = $this->payments->find($id);
        if ($payment === null) {
            abort(404);
        }

        $detail = $this->presentDetail($payment);

        return $this->receiptPdf->generate($detail)->stream("recibo-{$payment->numero_recibo}.pdf");
    }

    /** PUT /api/admin-contab/payments/{id}/confirm — REQ-475, confirmación manual bancaria. */
    public function confirm(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $payment = $this->payments->confirm($id, $actor);
        } catch (PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentDetail($payment));
    }

    /**
     * POST /api/admin-contab/payments/{id}/retention-attachment — Batch 19 (REQ-525), sube o
     * reemplaza la constancia de retención. Funciona sobre un pago de cualquier arqueo, cerrado o
     * no (RN4) — sin guard de estado del `daily_cash_count`.
     */
    public function retentionAttachment(int $id, UploadPaymentRetentionAttachmentRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $payment = $this->payments->find($id);
        if ($payment === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($payment->metodo_pago !== AdminContPayment::METODO_RETENCION_IMPUESTOS) {
            return response()->json(['message' => 'Este cobro no fue registrado con método "Retención de impuestos".'], 422);
        }

        $attachment = $this->retentionAttachments->upload($request->file('file'), $payment, $actor->id);

        return response()->json([
            'nombre_archivo' => $attachment->nombre_archivo,
            'url'            => $this->retentionAttachments->presignedUrl($attachment),
            'uploaded_at'    => $attachment->updated_at->toIso8601String(),
        ], 201);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
