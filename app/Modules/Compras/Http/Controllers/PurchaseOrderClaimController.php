<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compras\Events\ClaimCreated;
use App\Modules\Compras\Events\ClaimStatusChanged;
use App\Modules\Compras\Http\Requests\StorePurchaseOrderClaimRequest;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrderClaim;
use App\Modules\Compras\Models\PurchaseOrderClaimLine;
use App\Modules\Compras\Models\PurchaseOrderClaimPhoto;
use App\Modules\Compras\Services\PurchaseOrderClaimPdfService;
use App\Modules\Compras\Services\PurchaseOrderClaimPhotoService;
use App\Shared\Http\Pagination;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SCRUM-257→261 (REQ-194→198) — Garantías y Reclamos.
 *
 * REQ-198 (vínculo a Servicios) — alcance reducido acordado con Luis (2026-07-17): el campo
 * `service_ticket_id` existe en el schema para no requerir otra migración cuando exista Fase 4 —
 * Servicios, pero este controller nunca lo consulta contra un módulo real — `format()` siempre
 * devuelve `service_tickets: []`, el único escenario de aceptación que el ticket define hoy.
 */
final class PurchaseOrderClaimController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderClaimPhotoService $photos,
        private readonly PurchaseOrderClaimPdfService $pdfService,
    ) {}

    /**
     * REQ-194/REQ-195 (hallazgo de Gerencia Test 2026-08-09/2026-08-10) — encabezado con los 4
     * KPIs nuevos (Total/Reportados-En revisión/Resueltos este mes/Monto en disputa, reemplazan
     * a los 4 anteriores que incluían "Atención" como tarjeta — esa señal sigue existiendo como
     * `needs_attention` por fila y como chip de filtro, ya no como KPI aparte), filtro por chip
     * de estado, filtro de proveedor, filtro de tipo de resolución y búsqueda de texto libre,
     * combinables entre sí.
     *
     * Los KPIs se calculan siempre sobre el universo COMPLETO de reclamos (no sobre el resultado
     * ya filtrado) — son un resumen global de la pantalla, los filtros solo acotan la tabla de
     * abajo. Dataset de Reclamos es chico (no hay paginación en esta pantalla todavía), se filtra
     * en PHP sobre la colección ya cargada en vez de repetir queries, mismo patrón que
     * InventoryController.
     *
     * "Reportado" (chip nuevo pedido por Daniela) NO es un estado real distinto de "en_revision"
     * en el modelo actual — un reclamo nace directamente en en_revision, nunca hay un estado
     * intermedio. El chip filtra el mismo conjunto que "En revisión" hasta que exista una
     * decisión de Luis sobre si debe ser un estado real separado (documentado en el comentario
     * de Jira de SCRUM-257, no resuelto acá).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $attentionDays = ComprasSettings::current()->claim_attention_days;

        /** @var Collection<int, PurchaseOrderClaim> $allClaims */
        $allClaims = PurchaseOrderClaim::with(['purchaseOrder.provider', 'lines.orderLine.catalogProduct'])
            ->orderByDesc('created_at')
            ->get();

        $filtered = $allClaims;

        $chip = (string) $request->query('chip', 'todos');
        $filtered = match ($chip) {
            'atencion' => $filtered->filter(fn (PurchaseOrderClaim $c): bool => $c->needsAttention($attentionDays)),
            'reportado', 'en_revision' => $filtered->where('status', PurchaseOrderClaim::STATUS_EN_REVISION),
            'resuelto' => $filtered->where('status', PurchaseOrderClaim::STATUS_RESUELTO),
            default => $filtered,
        };

        $providerId = $request->query('provider_id');
        if (is_numeric($providerId)) {
            $filtered = $filtered->filter(fn (PurchaseOrderClaim $c): bool => $c->purchaseOrder?->provider_id === (int) $providerId);
        }

        $resolution = $request->query('expected_resolution');
        if (is_string($resolution) && $resolution !== '' && $resolution !== 'all') {
            $filtered = $filtered->where('expected_resolution', $resolution);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $filtered = $filtered->filter(function (PurchaseOrderClaim $c) use ($needle): bool {
                $products = $c->lines->map(fn (PurchaseOrderClaimLine $l): ?string => $l->orderLine?->catalogProduct?->name)->filter()->all();
                $haystack = mb_strtolower(implode(' ', array_filter([$c->code(), $c->purchaseOrder?->provider?->name, ...$products])));

                return str_contains($haystack, $needle);
            });
        }

        $currentMonthStart = now()->startOfMonth();
        $providers = Provider::orderBy('name')->get(['id', 'name']);

        // SCRUM-754 — kpis siguen sobre $allClaims (sin filtros, sin cambios); solo data pagina.
        $pagination = Pagination::params($request, [], 'created_at');
        $paginator = Pagination::ofArray(array_values($filtered->values()->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values(array_map(fn (PurchaseOrderClaim $c) => $this->formatSummary($c, $attentionDays), $paginator->items())),
            'meta' => Pagination::meta($paginator),
            'kpis' => [
                'total' => $allClaims->count(),
                'reportados_en_revision' => $allClaims->where('status', PurchaseOrderClaim::STATUS_EN_REVISION)->count(),
                'resueltos_este_mes' => $allClaims->filter(fn (PurchaseOrderClaim $c): bool => $c->status === PurchaseOrderClaim::STATUS_RESUELTO
                    && $c->resolved_at !== null && $c->resolved_at->greaterThanOrEqualTo($currentMonthStart))->count(),
                'disputed_amount' => round((float) $allClaims->sum(fn (PurchaseOrderClaim $c): float => $c->totalAmount()), 2),
            ],
            'filters' => [
                'providers' => $providers->map(fn (Provider $p): array => ['id' => $p->id, 'name' => $p->name])->values(),
            ],
        ]);
    }

    /** REQ-196 — ficha completa: descripción, líneas, monto, fotos, historial, acceso a la orden. */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $claim = PurchaseOrderClaim::with(['purchaseOrder.provider', 'lines.orderLine.catalogProduct', 'photos', 'creator'])->find($id);
        if ($claim === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($claim));
    }

    /** REQ-197 — busca la orden afectada, agrega productos (validados en StorePurchaseOrderClaimRequest), define resolución esperada. */
    public function store(StorePurchaseOrderClaimRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{purchase_order_id: int, description?: string|null, expected_resolution?: string|null, lines: list<array{purchase_order_line_id: int, affected_quantity: int, description?: string|null}>} $validated */
        $validated = $request->validated();

        $claim = DB::transaction(function () use ($validated, $user): PurchaseOrderClaim {
            $claim = PurchaseOrderClaim::create([
                'purchase_order_id' => $validated['purchase_order_id'],
                'description' => $validated['description'] ?? null,
                'expected_resolution' => $validated['expected_resolution'] ?? null,
                'status' => PurchaseOrderClaim::STATUS_EN_REVISION,
                'status_changed_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($validated['lines'] as $line) {
                PurchaseOrderClaimLine::create([
                    'claim_id' => $claim->id,
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'affected_quantity' => $line['affected_quantity'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $claim;
        });

        $claim->load(['purchaseOrder.provider', 'lines.orderLine.catalogProduct', 'photos', 'creator']);

        event(new ClaimCreated(claimId: $claim->id, orderId: $claim->purchase_order_id, createdBy: $user->id, tenant: (string) Tenant::current()?->slug));

        return response()->json($this->formatDetail($claim), 201);
    }

    /** REQ-195 — cambio de estado en línea desde la tabla. */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $claim = PurchaseOrderClaim::find($id);
        if ($claim === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.PurchaseOrderClaim::STATUS_EN_REVISION.','.PurchaseOrderClaim::STATUS_RESUELTO],
        ]);

        $previousStatus = $claim->status;
        $claim->update([
            'status' => $validated['status'],
            'status_changed_at' => now(),
            'resolved_at' => $validated['status'] === PurchaseOrderClaim::STATUS_RESUELTO ? now() : null,
        ]);

        event(new ClaimStatusChanged(
            claimId: $claim->id,
            previousStatus: $previousStatus,
            newStatus: $claim->status,
            changedBy: $user->id,
            tenant: (string) Tenant::current()?->slug,
        ));

        // SCRUM-259 (REQ-196) — hallazgo de QA: "historial de estados" es parte explícita de la
        // ficha completa que pide el ticket, pero no existía ningún registro más allá del último
        // status_changed_at. Se reusa audit_logs (mismo patrón que SCRUM-211) en vez de una tabla
        // nueva — un reclamo no cambia de estado con frecuencia suficiente como para justificarla.
        app(AuditService::class)->log(
            action: AuditService::COMPRAS_CLAIM_STATUS_CHANGED,
            entityType: 'purchase_order_claim',
            entityId: $claim->id,
            oldValues: ['status' => $previousStatus],
            newValues: ['status' => $claim->status],
        );

        $attentionDays = ComprasSettings::current()->claim_attention_days;

        return response()->json($this->formatSummary($claim, $attentionDays));
    }

    /**
     * SCRUM-259 (REQ-196, hallazgo de Gerencia Test 2026-08-10) — "Tipo de resolución" pasa a
     * ser editable desde el detalle del reclamo, no solo al crearlo. Mismo catálogo fijo que
     * StorePurchaseOrderClaimRequest (PurchaseOrderClaim::RESOLUTION_TYPES).
     */
    public function updateResolution(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $claim = PurchaseOrderClaim::find($id);
        if ($claim === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'expected_resolution' => ['required', 'string', Rule::in(PurchaseOrderClaim::RESOLUTION_TYPES)],
        ]);

        $claim->update(['expected_resolution' => $validated['expected_resolution']]);
        $claim->refresh()->load(['purchaseOrder.provider', 'lines.orderLine.catalogProduct', 'photos', 'creator']);

        return response()->json($this->formatDetail($claim));
    }

    /** REQ-196 — foto de evidencia. */
    public function storePhoto(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $claim = PurchaseOrderClaim::find($id);
        if ($claim === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'photo' => ['required', 'file', 'max:20480', 'extensions:jpg,jpeg,png,gif,webp'],
        ]);

        $photo = $this->photos->upload($validated['photo'], $claim->id, $user->id);

        return response()->json($this->formatPhoto($photo), 201);
    }

    /** REQ-196 — PDF real del reclamo. */
    public function pdf(Request $request, int $id): Response|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $claim = PurchaseOrderClaim::find($id);
        if ($claim === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $pdf = $this->pdfService->generate($claim);

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — documento formal del reclamo.
        app(AuditService::class)->log(
            action: AuditService::COMPRAS_CLAIM_PDF_DOWNLOADED,
            entityType: 'purchase_order_claim',
            entityId: $claim->id,
        );

        // SCRUM-259 (hallazgo de Gerencia Test) — "Descargar PDF del reclamo", no "Ver PDF":
        // descarga real (mismo patrón que InspectionReportController), no vista inline.
        return $pdf->download("reclamo-{$claim->code()}.pdf");
    }

    /** @return array<string, mixed> */
    private function formatSummary(PurchaseOrderClaim $claim, int $attentionDays): array
    {
        // SCRUM-258 (hallazgo de Gerencia Test) — columna "Producto": nombre del primer producto
        // de la línea + conteo de adicionales si hay más de uno (un reclamo puede tener varios
        // productos, la tabla es de una fila por reclamo, no por línea).
        $productNames = $claim->lines->map(fn (PurchaseOrderClaimLine $l): ?string => $l->orderLine?->catalogProduct?->name)->filter()->values();
        $product = $productNames->first();
        if ($productNames->count() > 1) {
            $product .= ' (+'.($productNames->count() - 1).')';
        }

        return [
            'id' => $claim->id,
            'code' => $claim->code(),
            'purchase_order_id' => $claim->purchase_order_id,
            'provider_name' => $claim->purchaseOrder?->provider?->name,
            'provider_id' => $claim->purchaseOrder?->provider_id,
            'product' => $product,
            'expected_resolution' => $claim->expected_resolution,
            'status' => $claim->status,
            'status_changed_at' => $claim->status_changed_at->toIso8601String(),
            'needs_attention' => $claim->needsAttention($attentionDays),
            'total_amount' => $claim->totalAmount(),
            'created_at' => $claim->created_at->toIso8601String(),
            // REQ-198, alcance reducido (ver docblock de la clase) — siempre null hasta que
            // exista el vínculo real con Servicios.
            'service_ticket' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(PurchaseOrderClaim $claim): array
    {
        $attentionDays = ComprasSettings::current()->claim_attention_days;

        return [
            ...$this->formatSummary($claim, $attentionDays),
            'description' => $claim->description,
            'expected_resolution' => $claim->expected_resolution,
            'resolved_at' => $claim->resolved_at?->toIso8601String(),
            // REQ-198, alcance reducido — ver docblock de la clase.
            'service_tickets' => [],
            'status_history' => $this->statusHistory($claim),
            'lines' => $claim->lines->map(fn (PurchaseOrderClaimLine $l): array => [
                'id' => $l->id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'description' => $l->orderLine?->catalogProduct?->name,
                'affected_quantity' => $l->affected_quantity,
                'unit_cost' => $l->orderLine?->unit_cost,
                'subtotal' => $l->subtotal(),
            ])->values(),
            'photos' => $claim->photos->map(fn (PurchaseOrderClaimPhoto $p) => $this->formatPhoto($p))->values(),
        ];
    }

    /**
     * SCRUM-259 (REQ-196) — el primer evento es siempre la creación (status inicial "en_revision",
     * sin `old_values`), seguida de cada cambio real registrado en audit_logs, en orden
     * cronológico. `creator` ya viene precargado por show()/store(), no dispara N+1.
     *
     * @return list<array{from_status: string|null, to_status: string, changed_by: string|null, changed_at: string}>
     */
    private function statusHistory(PurchaseOrderClaim $claim): array
    {
        $creator = $claim->creator;
        $history = [[
            'from_status' => null,
            'to_status' => PurchaseOrderClaim::STATUS_EN_REVISION,
            'changed_by' => $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null,
            'changed_at' => $claim->created_at->toIso8601String(),
        ]];

        $changes = AuditLog::where('entity_type', 'purchase_order_claim')
            ->where('entity_id', (string) $claim->id)
            ->where('action', AuditService::COMPRAS_CLAIM_STATUS_CHANGED)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        foreach ($changes as $change) {
            $history[] = [
                'from_status' => $change->old_values['status'] ?? null,
                'to_status' => $change->new_values['status'] ?? '',
                'changed_by' => $change->user !== null
                    ? trim("{$change->user->first_name} {$change->user->last_name}")
                    : null,
                'changed_at' => $change->created_at->toIso8601String(),
            ];
        }

        return $history;
    }

    /** @return array<string, mixed> */
    private function formatPhoto(PurchaseOrderClaimPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => $this->photos->presignUrl($photo),
            'created_at' => $photo->created_at->toIso8601String(),
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
