<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\CreditNoteStateException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentPreviewService;
use App\Modules\AdminCont\Http\Requests\CreditNoteDecisionRequest;
use App\Modules\AdminCont\Http\Requests\PreviewCreditNoteCorreccionRequest;
use App\Modules\AdminCont\Http\Requests\RegisterCreditNoteCorreccionRequest;
use App\Modules\AdminCont\Http\Requests\RegisterCreditNoteRequest;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Services\CreditNotePdfService;
use App\Modules\AdminCont\Services\CreditNoteService;
use App\Modules\Bodega\Exceptions\CustomerReturnException;
use App\Shared\Http\Pagination;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Batch 10 de Admin&Cont (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones
 * (solo lectura). Batch 11 (SCRUM-559→564, REQ-482→487) agrega `facturas()` y `store()` — el
 * submit real. Batch 13 (SCRUM-571→574, REQ-494→497) agrega `decision()` (exclusivo Gerencia, ver
 * middleware `primary_approver_only` en routes/admin-contab.php), `comprobante()` y `pdf()`. Ver docblock de
 * `CreditNoteService::register()`/`decide()` para el alcance exacto de cada uno.
 */
class AdminContCreditNoteController
{
    public function __construct(
        private readonly CreditNoteService $creditNotes,
        private readonly CreditNotePdfService $creditNotePdf,
        private readonly FiscalDocumentPreviewService $documentPreview,
        private readonly FiscalCertificationService $certification,
    ) {}

    /** GET /api/admin-contab/notas-credito/summary — REQ-477, 5 tarjetas de indicadores del mes. */
    public function summary(): JsonResponse
    {
        return response()->json($this->creditNotes->summary());
    }

    /** GET /api/admin-contab/notas-credito/itbms-rates — REQ-480: catálogo de tasas activas. */
    public function itbmsRates(): JsonResponse
    {
        return response()->json($this->creditNotes->activeItbmsRates());
    }

    /**
     * GET /api/admin-contab/notas-credito/facturas/{order}/itbms-treatment — REQ-480: tratamiento
     * ITBMS actual de la factura (solo lectura) + tasas activas que se pueden ofrecer como
     * "tratamiento correcto".
     */
    public function itbmsTreatment(int $order): JsonResponse
    {
        $result = $this->creditNotes->itbmsTreatmentForInvoice($order);
        if ($result === null) {
            return response()->json(['message' => 'Esta entrega no tiene una factura generada.'], 404);
        }

        return response()->json($result);
    }

    /** GET /api/admin-contab/notas-credito/clientes/{masterClient}/facturas — REQ-483. */
    public function facturas(int $masterClient): JsonResponse
    {
        return response()->json(['data' => $this->creditNotes->invoicesForClient($masterClient)]);
    }

    /** POST /api/admin-contab/notas-credito — REQ-482→487, registra la nota completa. */
    public function store(RegisterCreditNoteRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $note = $this->creditNotes->register(
                data: [
                    'master_client_id' => (int) $request->validated('master_client_id'),
                    'cliente' => (string) $request->validated('cliente'),
                    'tipo' => (string) $request->validated('tipo'),
                    'subtipo_anulacion' => $request->validated('subtipo_anulacion'),
                    'factura_origen_id' => (int) $request->validated('factura_origen_id'),
                    'monto' => $request->validated('monto') !== null ? (float) $request->validated('monto') : null,
                    'motivo' => (string) $request->validated('motivo'),
                    'resultado' => $request->validated('resultado'),
                    'cuenta_bancaria_salida_id' => $request->validated('cuenta_bancaria_salida_id') !== null ? (int) $request->validated('cuenta_bancaria_salida_id') : null,
                    'mercancia_regresa_bodega' => $request->has('mercancia_regresa_bodega') ? $request->boolean('mercancia_regresa_bodega') : null,
                    'devolucion_bodega_id' => $request->validated('devolucion_bodega_id') !== null ? (int) $request->validated('devolucion_bodega_id') : null,
                ],
                comprobante: $request->file('comprobante'),
                actor: $actor,
            );
        } catch (CustomerReturnException $e) {
            // Batch 12 (REQ-491) — `register()` cierra el círculo con Bodega dentro de su propia
            // transacción; si esa devolución ya no está esperando nota (doble submit/race), la nota
            // completa revierte y se lo reportamos igual que Bodega lo hace en sus propios endpoints.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json($this->present($note), 201);
    }

    /** POST /api/admin-contab/notas-credito/correccion/preview — REQ-489, solo lectura. */
    public function previewCorreccion(PreviewCreditNoteCorreccionRequest $request): JsonResponse
    {
        try {
            $result = $this->creditNotes->previewCorreccion([
                'master_client_id' => (int) $request->validated('master_client_id'),
                'factura_origen_id' => (int) $request->validated('factura_origen_id'),
                'motivo_correccion' => (string) $request->validated('motivo_correccion'),
                'nuevo_tratamiento_itbms_rate_id' => $request->validated('nuevo_tratamiento_itbms_rate_id') !== null ? (int) $request->validated('nuevo_tratamiento_itbms_rate_id') : null,
                'nueva_fecha' => $request->validated('nueva_fecha'),
                'motivo' => (string) $request->validated('motivo'),
            ]);
        } catch (CreditNoteStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => CreditNoteService::MSG_FACTURA_NO_CORREGIBLE], 404); // SCRUM-1019
        }

        return response()->json($result);
    }

    /** POST /api/admin-contab/notas-credito/correccion — REQ-488/489/490, registro real. */
    public function storeCorreccion(RegisterCreditNoteCorreccionRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $note = $this->creditNotes->registerCorreccion(
                data: [
                    'master_client_id' => (int) $request->validated('master_client_id'),
                    'factura_origen_id' => (int) $request->validated('factura_origen_id'),
                    'motivo_correccion' => (string) $request->validated('motivo_correccion'),
                    'nuevo_tratamiento_itbms_rate_id' => $request->validated('nuevo_tratamiento_itbms_rate_id') !== null ? (int) $request->validated('nuevo_tratamiento_itbms_rate_id') : null,
                    'nueva_fecha' => $request->validated('nueva_fecha'),
                    'motivo' => (string) $request->validated('motivo'),
                ],
                comprobante: $request->file('comprobante'),
                actor: $actor,
            );
        } catch (CreditNoteStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => CreditNoteService::MSG_FACTURA_NO_CORREGIBLE], 404); // SCRUM-1019
        }

        return response()->json($this->present($note), 201);
    }

    /**
     * GET /api/admin-contab/notas-credito — REQ-492, historial combinado + filtros. SCRUM-947 —
     * paginación estándar (el servicio ya devuelve el array ordenado por fecha desc).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->creditNotes->index([
            'search' => $request->query('search'),
            'cliente' => $request->query('cliente') !== null ? (int) $request->query('cliente') : null,
            'tipo' => $request->query('tipo'),
            'estado' => $request->query('estado'),
        ]);

        $pagination = Pagination::params($request, [], 'fecha', 'desc');
        $paginator = Pagination::ofArray(array_values($rows), $pagination['per_page'], $pagination['page']);

        return response()->json(['data' => $paginator->items(), 'meta' => Pagination::meta($paginator)]);
    }

    /** GET /api/admin-contab/notas-credito/{creditNote} — REQ-493, detalle. */
    public function show(int $creditNote, Request $request): JsonResponse
    {
        $result = $this->creditNotes->show($creditNote);
        if ($result === null) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        // Pre-QA Batch 13 (2026-08-24) — `puede_aprobar_rechazar` (REQ-494) es dependiente del
        // actor, no solo del estado de la nota: sin este chequeo, Contabilidad/Asistente/Gerencia veían los
        // botones Aprobar/Rechazar en cualquier nota pendiente (el backend igual los bloqueaba con
        // un 403 claro al intentar decidir, nunca un gap de seguridad, pero sí un patrón
        // inconsistente respecto del gemelo REQ-448 en Facturación —
        // `puede_decidir_incobrable`/`AdminContInvoiceController::detail()` — que sí resuelve el
        // actor acá mismo). Mismo criterio: `superadmin.all` bypassa, igual que `primary_approver_only`.
        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');
        $isMark = AdminContFiscalSettings::actorEsMarkOSuperadmin($payload);

        $result['puede_aprobar_rechazar'] = $result['puede_aprobar_rechazar'] && $isMark;
        // El texto del rechazo del PAC es información de Configuración Fiscal: solo Gerencia/superadmin.
        // Se agrega acá (deny-by-default) y no en `CreditNoteService::show()`, que también consumen
        // Bodega y un job de correo sin este gate.
        $result['puede_ver_fe_transmisiones'] = $isMark;
        $notaMark = $isMark ? AdminContCreditNote::query()->whereKey($creditNote)->first() : null;
        $result['fe_detalle'] = $notaMark?->fe_detalle;
        // SCRUM-1007 — 'reintentar' | 'actualizar_estado' | null; solo Gerencia/superadmin y solo si el documento lo admite.
        $result['accion_fe'] = $notaMark !== null ? $this->certification->accionDisponible($notaMark, 'nota_credito') : null;
        $result['puede_reconciliar_fe'] = $notaMark !== null && $this->certification->reconciliacionDisponible($notaMark, 'nota_credito'); // SCRUM-1019

        return response()->json($result);
    }

    /**
     * PUT /api/admin-contab/notas-credito/{creditNote}/decision — REQ-494, exclusivo Gerencia (gate
     * real: middleware `primary_approver_only` sobre esta ruta, ver routes/admin-contab.php).
     */
    public function decision(int $creditNote, CreditNoteDecisionRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $note = $this->creditNotes->decide(
                id: $creditNote,
                approve: $request->boolean('approve'),
                motivoRechazo: $request->validated('motivo_rechazo'),
                actor: $actor,
            );
        } catch (CreditNoteStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // `show()` en vez de `present()` — trae estado/aprobado_por/fecha_decision/motivo_rechazo
        // ya resueltos, mismo shape que el modal de detalle consume, así el frontend refresca sin
        // pegarle a un segundo endpoint.
        $result = $this->creditNotes->show($note->id);
        if ($result !== null) {
            // Ruta `primary_approver_only`: quien llega acá ya puede ver Configuración Fiscal. Sin esto el
            // frontend (`setQueryData` con esta respuesta) no mostraría el motivo hasta recargar.
            $notaDecidida = AdminContCreditNote::query()->whereKey($note->id)->first();
            $result['fe_detalle'] = $notaDecidida?->fe_detalle;
            $result['puede_ver_fe_transmisiones'] = true; // idem: sin esto el visor se desmontaría al aprobar (setQueryData)
            $result['accion_fe'] = $notaDecidida !== null ? $this->certification->accionDisponible($notaDecidida, 'nota_credito') : null;
            $result['puede_reconciliar_fe'] = $notaDecidida !== null && $this->certification->reconciliacionDisponible($notaDecidida, 'nota_credito');
        }

        return response()->json($result);
    }

    /** GET /api/admin-contab/notas-credito/{creditNote}/comprobante — REQ-495. */
    public function comprobante(int $creditNote): JsonResponse
    {
        $result = $this->creditNotes->comprobante($creditNote);
        if ($result === null) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        return response()->json($result);
    }

    /**
     * GET /api/admin-contab/notas-credito/{creditNote}/fe-preview — SCRUM-943, mismo criterio que
     * `AdminContInvoiceController::fePreview()`: valida la estructura del documento que se armaría
     * para el PAC sin certificar nada.
     */
    public function fePreview(int $creditNote): JsonResponse
    {
        /** @var AdminContCreditNote|null $note */
        $note = AdminContCreditNote::find($creditNote);
        if ($note === null) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        return response()->json($this->documentPreview->paraNotaCredito($note));
    }

    /** GET /api/admin-contab/notas-credito/{creditNote}/pdf — REQ-496, documento formal. */
    public function pdf(int $creditNote): Response
    {
        $detail = $this->creditNotes->show($creditNote);
        abort_if($detail === null, 404, 'Nota de crédito no encontrada.');

        return $this->creditNotePdf->generate($detail)->stream("nota-credito-{$detail['numero']}.pdf");
    }

    /**
     * GET /api/admin-contab/notas-credito/devoluciones/{customerReturn} — REQ-491, precarga del
     * formulario de registro al hacer clic en una fila ámbar de la cola de Bodega.
     */
    public function devolucionDetail(int $customerReturn): JsonResponse
    {
        $result = $this->creditNotes->devolucionDetail($customerReturn);
        if ($result === null) {
            return response()->json(['message' => 'Esta devolución no existe o ya no está pendiente de generar nota.'], 404);
        }

        return response()->json($result);
    }

    /**
     * GET /api/admin-contab/notas-credito/devoluciones/{customerReturn}/signed-document —
     * SCRUM-865 punto 2, presign bajo el permiso de Notas de Crédito (ver docblock del método
     * homónimo en `CreditNoteService`).
     */
    public function devolucionSignedDocumentUrl(int $customerReturn): JsonResponse
    {
        $url = $this->creditNotes->devolucionSignedDocumentUrl($customerReturn);
        if ($url === null) {
            return response()->json(['message' => 'Esta devolución no tiene documento firmado.'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /** @return array<string, mixed> */
    private function present(AdminContCreditNote $note): array
    {
        return [
            'id' => $note->id,
            'numero' => $note->numero,
            'master_client_id' => $note->master_client_id,
            'cliente' => $note->cliente,
            'tipo' => $note->tipo,
            'subtipo_anulacion' => $note->subtipo_anulacion,
            'factura_origen_id' => $note->factura_origen_id,
            'monto' => $note->monto,
            'subtotal' => $note->subtotal,
            'itbms' => $note->itbms,
            'motivo' => $note->motivo,
            'resultado' => $note->resultado,
            'cuenta_bancaria_salida_id' => $note->cuenta_bancaria_salida_id,
            'estado' => $note->estado,
            'tiene_comprobante' => $note->comprobante_url !== null,
            'fecha' => $note->fecha->toDateString(),
            'created_at' => $note->created_at->toIso8601String(),
        ];
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
