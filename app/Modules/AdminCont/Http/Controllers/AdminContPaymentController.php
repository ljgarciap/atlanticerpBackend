<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Http\Requests\StorePaymentRequest;
use App\Modules\AdminCont\Http\Requests\StorePaymentV2Request;
use App\Modules\AdminCont\Http\Requests\UploadPaymentRetentionAttachmentRequest;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentReversalRequest;
use App\Modules\AdminCont\Services\AdminContPaymentRetentionAttachmentService;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\PaymentLedger;
use App\Modules\AdminCont\Services\PaymentReceiptPdfService;
use App\Modules\AdminCont\Services\PaymentService;
use App\Modules\AdminCont\Support\GuiaEntregaResolver;
use App\Modules\Bodega\Models\Order;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        private readonly CobrosConfigService $cobrosConfig,
        private readonly PaymentLedger $ledger,
    ) {}

    /** GET /api/admin-contab/payments/summary — REQ-463, 4 tarjetas del mes en curso. */
    public function summary(): JsonResponse
    {
        return response()->json($this->payments->summaryForCurrentMonth());
    }

    /**
     * GET /api/admin-contab/payments/clients?search=&only_with_invoice=&only_with_pending_invoice=
     * — REQ-462, buscador de cliente del formulario. `only_with_invoice` (SCRUM-865) lo pasa el
     * formulario de Notas de Crédito. `only_with_pending_invoice` (SCRUM-905) lo pasa "Registrar
     * nuevo cobro" — más angosto, solo clientes con algo pendiente de cobro. Ambos default false,
     * independientes entre sí (ver docblock de `PaymentService::searchMasterClients()`).
     */
    public function clients(Request $request): JsonResponse
    {
        $search = (string) $request->query('search', '');
        $onlyWithInvoice = $request->boolean('only_with_invoice');
        $onlyWithPendingInvoice = $request->boolean('only_with_pending_invoice');

        return response()->json(['data' => $this->payments->searchMasterClients($search, $onlyWithInvoice, $onlyWithPendingInvoice)]);
    }

    /** GET /api/admin-contab/payments/open-invoices?master_client_id= — REQ-464 RN1. */
    public function openInvoices(Request $request): JsonResponse
    {
        $request->validate(['master_client_id' => ['required', 'integer']]);
        $masterClientId = (int) $request->integer('master_client_id');

        $credito = $this->payments->creditBreakdownForClient($masterClientId);

        return response()->json([
            'invoices'          => $this->payments->openInvoicesForClient($masterClientId),
            // `credit_balance` = lo que un cobro puede aplicar de inmediato (sin cambios). Los otros tres cierran la cuenta con la pantalla
            // «Saldo a favor» y la compensación (Fase 13, 2.2): a cuenta (cobros sin aplicar), reservado (abonos de un proyecto o factura) y el total.
            'credit_balance'    => $this->payments->creditBalanceForClient($masterClientId),
            'credit_balance_cuenta'    => $credito['a_cuenta'],
            'credit_balance_reservado' => $credito['reservado'],
            'credit_balance_total'     => $credito['total'],
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

    /**
     * GET /api/admin-contab/payments — REQ-470, historial con búsqueda + filtros. SCRUM-947 —
     * paginación estándar sobre `rows`; `total` sigue siendo el conteo GLOBAL sin ningún filtro
     * (denominador de referencia ya existente, no se toca — ver PaymentService::historial()).
     */
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

        $pagination = Pagination::params($request, [], 'fecha', 'desc');
        $paginator  = Pagination::ofArray(array_values($result['rows']), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'rows'  => $paginator->items(),
            'total' => $result['total'],
            'meta'  => Pagination::meta($paginator),
        ]);
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

        // SCRUM-1168 F2 — con el concepto nuevo encendido el registro de cobros es otro (con o sin factura, aplicación decidida por el usuario);
        // el flujo anterior se bloquea para no mezclar dos modelos de saldo.
        if ($this->cobrosConfig->v2Activo()) {
            return response()->json(['message' => 'El registro de cobros cambió: use el nuevo formulario de cobros (con o sin factura).'], 422);
        }

        try {
            $payment = $this->payments->register(
                masterClientId: (int) $request->validated('master_client_id'),
                invoiceIds: $request->validated('invoice_ids'),
                montoSaldoClienteAplicado: $request->montoSaldoClienteAplicado(),
                metodoPago: (string) $request->validated('metodo_pago'),
                bankAccountId: $request->validated('bank_account_id') !== null ? (int) $request->validated('bank_account_id') : null,
                numeroDocumentoRetencion: $request->validated('numero_documento_retencion'),
                comentarioAjuste: $request->validated('comentario_ajuste'),
                referencia: $request->validated('referencia'),
                comprobante: $request->file('comprobante'),
                actor: $actor,
            );
        } catch (InvoiceStateException|PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($payment), 201);
    }

    /** POST /api/admin-contab/payments/cobro — SCRUM-1168 F2, registra un cobro del concepto nuevo (con o sin factura). */
    public function storeV2(StorePaymentV2Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        if (! $this->cobrosConfig->v2Activo()) {
            return response()->json(['message' => 'El nuevo registro de cobros todavía no está activo.'], 422);
        }

        try {
            $payment = $this->payments->registerV2(
                masterClientId: (int) $request->validated('master_client_id'),
                monto: (float) $request->validated('monto_recibido'),
                metodoPago: (string) $request->validated('metodo_pago'),
                bankAccountId: $request->validated('bank_account_id') !== null ? (int) $request->validated('bank_account_id') : null,
                numeroDocumentoRetencion: $request->validated('numero_documento_retencion'),
                comentarioAjuste: $request->validated('comentario_ajuste'),
                referencia: $request->validated('referencia'),
                comprobante: $request->file('comprobante'),
                aplicaciones: $request->aplicaciones(),
                actor: $actor,
            );
        } catch (InvoiceStateException|PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentDetail($payment->fresh() ?? $payment), 201);
    }

    /** GET /api/admin-contab/payments/config — SCRUM-1168 F2, qué concepto de cobros está activo y la ventana de «movimiento reciente». */
    public function config(): JsonResponse
    {
        return response()->json([
            'v2'                             => $this->cobrosConfig->v2Activo(),
            'compensaciones_dias_movimiento' => $this->cobrosConfig->diasMovimientoReciente(),
        ]);
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
            'cobros_v2'                   => (bool) $payment->cobros_v2,
            'anulado_at'                  => $payment->anulado_at?->toIso8601String(),
            'motivo_anulacion'            => $payment->motivo_anulacion,
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

        // SCRUM-931 — Cotización/Guía de Entrega por factura, mismo patrón que
        // `AccountStatementService::movements()`: se resuelven en lote (no una query por línea)
        // sobre los `order_id` de las facturas de ESTE cobro.
        $invoices = $payment->invoiceLines->pluck('invoice')->filter()->unique('id');
        $orders   = Order::query()
            ->whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        // SCRUM-931 — Notas de Crédito no tienen relación directa con el cobro, solo transitiva vía
        // la factura (`factura_origen_id`) — mismo query que ya usa `AccountStatementService`.
        $notasCredito = AdminContCreditNote::query()
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->whereIn('factura_origen_id', $invoices->pluck('id'))
            ->get();

        return [
            ...$this->present($payment),
            'cliente'           => $this->payments->masterClientName($payment->master_client_id),
            'metodo_pago_label' => PaymentMethods::label($payment->metodo_pago),
            'registrado_por'    => $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null,
            'bank_account'      => $payment->bankAccount?->label(),
            'tiene_comprobante' => $payment->attachment !== null,
            // RN1 REQ-473 — total antes de aplicar cualquier saldo a favor (abono obligatorio o
            // saldo Cliente opcional), para el recibo formal. SCRUM-902 — ya suma también el
            // abono obligatorio de cada línea, no solo `saldo_favor_aplicado` (nivel Cliente).
            'total_facturas'    => $payment->cobros_v2
                ? round((float) $payment->invoiceLines->sum('monto_aplicado'), 2)
                : round(
                    $payment->monto_recibido + $payment->saldo_favor_aplicado
                        + (float) $payment->invoiceLines->sum('monto_abono_aplicado'),
                    2,
                ),
            // SCRUM-1168 F2 — cobro del concepto nuevo: lo que NO se aplicó a facturas es saldo a favor del cliente. Confirmado: lo que dice el
            // libro (sigue vigente aunque luego se compense); esperando confirmación: lo que quedaría tras aplicar las líneas registradas.
            'saldo_sin_aplicar' => $payment->cobros_v2
                ? ($payment->estado === AdminContPayment::ESTADO_CONFIRMADO
                    ? $this->ledger->remanenteDeCobro($payment->id)
                    : round(max(0.0, $payment->monto_recibido - (float) $payment->invoiceLines->sum('monto_aplicado')), 2))
                : null,
            // SCRUM-902 §4.2 — desglose visible por factura: monto original, abono obligatorio
            // aplicado (si hubo), y neto — los 3 explícitos, no solo el resultado final. SCRUM-931
            // agrega quote_id/cotizacion_folio/order_id/guia_entrega_* para enlazar cada factura a
            // su Cotización y Guía de Entrega desde el detalle del cobro.
            'facturas'          => $payment->invoiceLines->map(function ($l) use ($orders) {
                $invoice = $l->invoice;
                $order   = $invoice !== null ? $orders->get($invoice->order_id) : null;
                $quote   = $order?->linkedQuote();
                [$guiaNumero, $guiaOrderId] = $invoice !== null
                    ? GuiaEntregaResolver::resolve($invoice, $order, $orders)
                    : [null, null];

                return [
                    'invoice_id'            => $l->invoice_id,
                    'numero'                => $invoice?->numero,
                    'monto_original'        => $invoice?->monto,
                    'monto_abono_aplicado'  => $l->monto_abono_aplicado,
                    'monto_aplicado'        => $l->monto_aplicado,
                    'quote_id'              => $quote?->id,
                    'cotizacion_folio'      => $quote?->folio,
                    'order_id'              => $order?->id,
                    'guia_entrega_numero'   => $guiaNumero,
                    'guia_entrega_order_id' => $guiaOrderId,
                ];
            })->values()->all(),
            // SCRUM-931 — un cobro puede cubrir varias facturas, cada una con su propio abono (el
            // pivote `payment_invoices.abono_id` es por línea, no hay uno solo "del cobro") — lista
            // de ids únicos, nunca inventa un abono que no exista.
            'abonos'            => $payment->invoiceLines->pluck('abono_id')->filter()->unique()->values()->all(),
            // SCRUM-931 — igual que arriba: puede haber varias notas, una o más por factura.
            'notas_credito'     => $notasCredito->map(fn (AdminContCreditNote $n) => [
                'id'     => $n->id,
                'numero' => $n->numero,
            ])->values()->all(),
            // SCRUM-1168 F4 — la última solicitud de anulación de este cobro (pendiente, ejecutada o rechazada), para mostrarla en el detalle.
            'solicitud_anulacion' => (function () use ($payment): ?array {
                $s = AdminContPaymentReversalRequest::query()->where('tipo', AdminContPaymentReversalRequest::TIPO_ANULACION)->where('payment_id', $payment->id)->orderByDesc('id')->first();

                return $s === null ? null : [
                    'id' => $s->id, 'estado' => $s->estado, 'motivo' => $s->motivo, 'motivo_rechazo' => $s->motivo_rechazo,
                    'created_at' => $s->created_at->toIso8601String(),
                ];
            })(),
            'confirmacion' => $confirmacion !== null ? [
                'numero_confirmacion' => $confirmacion->numero_confirmacion,
                'confirmado_por'      => $confirmacion->confirmedBy !== null
                    ? trim("{$confirmacion->confirmedBy->first_name} {$confirmacion->confirmedBy->last_name}")
                    : null,
                'confirmado_at' => $confirmacion->confirmed_at->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * POST /api/admin-contab/payments/{id}/datos (multipart) — SCRUM-1168 F5: después de confirmado, un cobro solo admite corregir lo que no afecta la
     * contabilidad: la referencia y el comprobante adjunto. Monto, método, cuenta, cliente o aplicaciones requieren anular el cobro y registrarlo de nuevo.
     */
    public function updateDatos(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate([
            'referencia'  => ['nullable', 'string', 'max:255'],
            'comprobante' => ['nullable', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,webp'],
        ]);
        $extra = array_diff(array_keys($request->all()), ['referencia', 'comprobante']);
        if ($extra !== []) {
            return response()->json(['message' => 'Solo se pueden corregir la referencia y el comprobante; para cambiar monto, método, cuenta o aplicaciones anula el cobro y regístralo de nuevo.'], 422);
        }

        /** @var AdminContPayment|null $payment */
        $payment = AdminContPayment::query()->find($id);
        if ($payment === null) {
            return response()->json(['message' => 'Cobro no encontrado.'], 404);
        }
        if ($payment->estado === AdminContPayment::ESTADO_ANULADO) {
            return response()->json(['message' => 'Un cobro anulado no se puede editar.'], 422);
        }

        DB::transaction(function () use ($payment, $data, $request, $actor): void {
            if (array_key_exists('referencia', $data) && $data['referencia'] !== null) {
                $payment->update(['referencia' => trim($data['referencia'])]);
            }
            if ($request->hasFile('comprobante')) {
                $this->payments->replaceComprobante($payment, $request->file('comprobante'), $actor->id);
            }
        });

        return response()->json(['id' => $payment->id, 'referencia' => $payment->referencia]);
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
        // SCRUM-936 — solo el PDF (documento que recibe el cliente) usa el número completo o
        // "-"; `presentDetail()` en sí no cambia, sigue enmascarado para show()/confirm().
        $detail['bank_account'] = $payment->bankAccount?->receiptLabel();

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
            $result = $this->payments->confirmWithAdjustments($id, $actor);
        } catch (PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // SCRUM-1168 F0 — si lo registrado ya no cabía en alguna factura, qué se aplicó y qué pasó a saldo a favor (null = tal cual).
        return response()->json($this->presentDetail($result['payment']) + ['ajustes_confirmacion' => $result['ajustes']]);
    }

    /** GET /api/admin-contab/payments/{id}/confirm-preview — SCRUM-1168 F0, qué pasaría al confirmar ahora (sin cambiar nada). */
    public function confirmPreview(int $id): JsonResponse
    {
        try {
            $ajustes = $this->payments->previewConfirmation($id);
        } catch (PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ajustes_confirmacion' => $ajustes]);
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
