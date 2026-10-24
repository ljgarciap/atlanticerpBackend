<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequestLine;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Services\ZonaLibreFolioService;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-433→439, 442→445 (REQ-363→369, 371→375) — lado Bodega del Bloque B3 "Zona Libre de
 * Colón": crear la solicitud (3C) y la bandeja de seguimiento (3D). La decisión de Yirena
 * (aprobar/rechazar) vive en Compras (`ComprasZonaLibreRequestController`) — ver ADR-SCRUM433-445
 * sección "Aclaración de ubicación de módulo".
 *
 * SCRUM-797 (2026-08-26) — revierte la decisión de SCRUM-440 a pedido explícito de Luis: mientras
 * la solicitud siga `pendiente`, Líder de Bodega o Líder de Compras SÍ pueden modificarla (`update()`
 * abajo). Una vez aprobada/rechazada por Compras, sigue siendo solo lectura — mismo guard de
 * estado (`isPending()` bajo `lockForUpdate()`) que usa `ComprasZonaLibreRequestController::
 * approve()`/`reject()` para la decisión, así evita una edición concurrente con esa decisión.
 * `DELETE` sigue sin existir — no lo pidió el ticket.
 */
final class BodegaZonaLibreRequestController extends Controller
{
    /**
     * `role_key` de Compras que resuelve estas solicitudes (Lider Compras Test, ver
     * `BusinessRoleUserSeeder`) — mismo criterio que `InventoryReviewService::COMPRAS_LEAD_ROLE_KEY`:
     * es un ROL, no una persona puntual configurable.
     */
    private const COMPRAS_APPROVER_ROLE_KEY = 'lider_compras';

    /**
     * SCRUM-797 — mismo permiso narrow que gatea aprobar/rechazar en
     * `ComprasZonaLibreRequestController` (`routes/compras.php`), reusado acá para identificar
     * "es Líder de Compras" en `update()` sin depender de `role_key` (la Líder de Compras no
     * necesariamente tiene `bodega.write`/`bodega.read` amplio — solo este grant angosto vía
     * `SpecialPermissionSeeder`).
     */
    private const COMPRAS_APPROVE_PERMISSION = 'compras.zona-libre.approve';

    public function __construct(
        private readonly ZonaLibreFolioService $folioService,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * REQ-364 — expone `zona_libre_provider_id`/nombre a Bodega (que no tiene `compras.read`,
     * único permiso que hoy da acceso a `GET /compras/settings`) para que 3C pueda resolver qué
     * `provider_id` pasarle al buscador de productos (`GET /compras/products`, ya permite
     * `bodega.read` vía OR de permisos).
     */
    public function provider(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $providerId = ComprasSettings::current()->zona_libre_provider_id;
        if ($providerId === null) {
            return response()->json(['message' => 'No hay un proveedor de Zona Libre configurado.'], 422);
        }

        return response()->json(['id' => $providerId, 'name' => $this->providerName()]);
    }

    /** REQ-442/443 — bandeja, filtro por status (4 chips: todas/pendiente/aprobada/rechazada). */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = BodegaZonaLibreRequest::with(['lines.catalogProduct', 'requester']);

        $status = $request->query('status');
        if (is_string($status) && $status !== '' && $status !== 'todas') {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('created_at')->get();
        $providerName = $this->providerName();

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'created_at');
        $paginator = Pagination::ofArray(array_values($requests->values()->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values(array_map(fn (BodegaZonaLibreRequest $r) => $this->formatRow($r, $providerName), $paginator->items())),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * REQ-364→369 — crea la solicitud con N líneas. RN1 (REQ-364/366): al menos 1 línea, cada una
     * con `quantity > 0`. Cada línea debe ser un producto del proveedor Zona Libre configurado —
     * revalidado server-side (mismo criterio que `StoreReplacementRequestRequest::
     * approvedProjectRule()`: nunca confiar solo en que el frontend ya filtró el buscador por
     * `provider_id`). `unit_cost_snapshot` lo calcula el backend desde `CatalogProduct::cost`,
     * nunca un valor mandado por el cliente.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'shipping_type' => ['nullable', 'string', 'in:aereo,maritimo,terrestre'],
            'estimated_arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $providerId = ComprasSettings::current()->zona_libre_provider_id;
        if ($providerId === null) {
            return response()->json(['message' => 'No hay un proveedor de Zona Libre configurado.'], 422);
        }

        /** @var list<array{catalog_product_id: int, quantity: int}> $lines */
        $lines = $validated['lines'];
        $productIds = array_values(array_unique(array_map(fn (array $l): int => (int) $l['catalog_product_id'], $lines)));

        /** @var Collection<int, CatalogProduct> $products */
        $products = CatalogProduct::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($lines as $line) {
            /** @var CatalogProduct|null $product */
            $product = $products->get($line['catalog_product_id']);
            if ($product === null || $product->provider_id !== $providerId) {
                return response()->json([
                    'message' => 'Uno o más productos no pertenecen al proveedor '.rtrim($this->providerName() ?? 'de Zona Libre', '.').'.',
                ], 422);
            }
        }

        $zonaLibreRequest = DB::transaction(function () use ($validated, $lines, $products, $user): BodegaZonaLibreRequest {
            $zonaLibreRequest = BodegaZonaLibreRequest::create([
                'order_number' => $this->folioService->next(),
                'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE,
                'requested_by' => $user->id,
                'shipping_type' => $validated['shipping_type'] ?? BodegaZonaLibreRequest::SHIPPING_TERRESTRE,
                'estimated_arrival_date' => $validated['estimated_arrival_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var CatalogProduct $product */
                $product = $products->get($line['catalog_product_id']);

                BodegaZonaLibreRequestLine::create([
                    'bodega_zona_libre_request_id' => $zonaLibreRequest->id,
                    'catalog_product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_cost_snapshot' => $product->cost ?? 0,
                ]);
            }

            return $zonaLibreRequest;
        });

        $zonaLibreRequest->load(['lines.catalogProduct', 'requester']);

        return response()->json($this->formatDetail($zonaLibreRequest, $this->providerName()), 201);
    }

    /**
     * SCRUM-797 RN10/RN11, CA7-CA9 — mientras la solicitud siga `pendiente`, Líder de Bodega
     * (`role_key === lider_bodega`) o Líder de Compras (`compras.zona-libre.approve`, mismo
     * permiso narrow que gatea aprobar/rechazar en Compras) pueden reemplazar sus datos/líneas.
     * Mismo shape de validación que `store()` — es un full-replace, no un PATCH parcial de líneas
     * puntuales (no hace falta preservar IDs de línea, la solicitud nunca generó su
     * `PurchaseOrder` todavía mientras está pendiente).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($user->role?->key !== BodegaRoles::LIDER_BODEGA && ! $user->hasPermission(self::COMPRAS_APPROVE_PERMISSION)) {
            return response()->json(['message' => 'Solo el Líder de Bodega o el Líder de Compras pueden modificar esta solicitud.'], 403);
        }

        $validated = $request->validate([
            'shipping_type' => ['nullable', 'string', 'in:aereo,maritimo,terrestre'],
            'estimated_arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $providerId = ComprasSettings::current()->zona_libre_provider_id;
        if ($providerId === null) {
            return response()->json(['message' => 'No hay un proveedor de Zona Libre configurado.'], 422);
        }

        /** @var list<array{catalog_product_id: int, quantity: int}> $lines */
        $lines = $validated['lines'];
        $productIds = array_values(array_unique(array_map(fn (array $l): int => (int) $l['catalog_product_id'], $lines)));

        /** @var Collection<int, CatalogProduct> $products */
        $products = CatalogProduct::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($lines as $line) {
            /** @var CatalogProduct|null $product */
            $product = $products->get($line['catalog_product_id']);
            if ($product === null || $product->provider_id !== $providerId) {
                return response()->json([
                    'message' => 'Uno o más productos no pertenecen al proveedor '.rtrim($this->providerName() ?? 'de Zona Libre', '.').'.',
                ], 422);
            }
        }

        $result = DB::transaction(function () use ($id, $validated, $lines, $products): array {
            /** @var BodegaZonaLibreRequest|null $zonaLibreRequest */
            $zonaLibreRequest = BodegaZonaLibreRequest::lockForUpdate()->find($id);
            if ($zonaLibreRequest === null) {
                return ['status' => 404];
            }
            if (! $zonaLibreRequest->isPending()) {
                return ['status' => 422, 'message' => 'Solo se puede modificar una solicitud pendiente.'];
            }

            $zonaLibreRequest->update([
                'shipping_type' => $validated['shipping_type'] ?? $zonaLibreRequest->shipping_type,
                'estimated_arrival_date' => $validated['estimated_arrival_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $zonaLibreRequest->lines()->delete();
            foreach ($lines as $line) {
                /** @var CatalogProduct $product */
                $product = $products->get($line['catalog_product_id']);

                BodegaZonaLibreRequestLine::create([
                    'bodega_zona_libre_request_id' => $zonaLibreRequest->id,
                    'catalog_product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_cost_snapshot' => $product->cost ?? 0,
                ]);
            }

            return ['status' => 200, 'request' => $zonaLibreRequest];
        });

        if ($result['status'] !== 200) {
            return response()->json(['message' => $result['message'] ?? 'Not found'], $result['status']);
        }

        /** @var BodegaZonaLibreRequest $zonaLibreRequest */
        $zonaLibreRequest = $result['request'];
        $zonaLibreRequest->load(['lines.catalogProduct', 'requester']);

        return response()->json($this->formatDetail($zonaLibreRequest, $this->providerName()));
    }

    /** REQ-444 — detalle, incluye `rejection_reason` cuando aplica ("Ver motivo"). */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $zonaLibreRequest = BodegaZonaLibreRequest::with([
            'lines.catalogProduct', 'requester', 'approver', 'rejecter', 'generatedOrder',
        ])->find($id);

        if ($zonaLibreRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($zonaLibreRequest, $this->providerName()));
    }

    /**
     * REQ-375 — email real (+ in-app) al rol `lider_compras` (Yirena) recordando que una solicitud
     * sigue pendiente de resolver. Reusa `NotificationService`/audience-por-rol, mismo mecanismo
     * que `InventoryReviewService::notifyShortage()` — nunca un email hardcodeado en el controller.
     */
    public function remind(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $zonaLibreRequest = BodegaZonaLibreRequest::find($id);
        if ($zonaLibreRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $zonaLibreRequest->isPending()) {
            return response()->json(['message' => 'Solo se puede recordar una solicitud pendiente.'], 422);
        }

        $roleId = Role::where('key', self::COMPRAS_APPROVER_ROLE_KEY)->value('id');
        /** @var list<int> $audience */
        $audience = $roleId !== null
            ? User::where('role_id', $roleId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        if ($audience === []) {
            return response()->json(['message' => 'No hay un aprobador de Compras configurado.'], 422);
        }

        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => "Recordatorio: solicitud Zona Libre #{$zonaLibreRequest->order_number} pendiente de aprobar",
                'body' => "La solicitud de Zona Libre de Colón #{$zonaLibreRequest->order_number} "
                    .'sigue pendiente de aprobación o rechazo en Compras.',
                // SCRUM-1167 — antes el aviso no llevaba enlace (clic = nada): la bandeja donde el Líder de Compras aprueba o rechaza.
                // SCRUM-1170 — ahora con `?request={id}`: la bandeja resalta esa solicitud y abre su detalle (antes quedaba en la bandeja general).
                'cta_url' => "/bodega/ordenes-zona-libre?request={$zonaLibreRequest->id}",

            ],
            channels: [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL],
            source: NotificationSend::SOURCE_BODEGA_ZONA_LIBRE_REMINDER,
            sourceRef: "bodega_zona_libre_request:{$zonaLibreRequest->id}",
            createdBy: $user->id,
        );

        return response()->json(['message' => 'Recordatorio enviado.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** El proveedor viene siempre de `compras_settings.zona_libre_provider_id`, nunca hardcodeado. */
    private function providerName(): ?string
    {
        $providerId = ComprasSettings::current()->zona_libre_provider_id;

        return $providerId !== null ? Provider::find($providerId)?->name : null;
    }

    /** @return array<string, mixed> */
    private function formatRow(BodegaZonaLibreRequest $r, ?string $providerName): array
    {
        $lines = $r->lines;
        $productsSummary = $lines->count() === 1
            ? $lines->first()?->catalogProduct?->name
            : "{$lines->count()} productos";

        return [
            'id' => $r->id,
            'order_number' => $r->order_number,
            'provider_name' => $providerName,
            'created_at' => $r->created_at->toIso8601String(),
            'products_summary' => $productsSummary,
            'total_amount' => round((float) $lines->sum(fn (BodegaZonaLibreRequestLine $l) => $l->subtotal()), 2),
            'estimated_arrival_date' => $r->estimated_arrival_date?->toDateString(),
            'shipping_type' => $r->shipping_type,
            'status' => $r->status,
            'requested_by_name' => $this->userName($r->requester),
            'generated_order_id' => $r->generated_order_id,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(BodegaZonaLibreRequest $r, ?string $providerName): array
    {
        $base = $this->formatRow($r, $providerName);

        return [
            ...$base,
            'notes' => $r->notes,
            'rejection_reason' => $r->rejection_reason,
            'approved_by_name' => $this->userName($r->approver),
            'rejected_by_name' => $this->userName($r->rejecter),
            'lines' => $r->lines->map(fn (BodegaZonaLibreRequestLine $l) => [
                'id' => $l->id,
                'catalog_product_id' => $l->catalog_product_id,
                'reference' => $l->catalogProduct?->reference,
                // SCRUM-797 — el formulario de "Editar" precarga el carrito desde estas líneas y
                // muestra la misma columna Ref. fábrica que "Nueva Orden" (buscador de productos).
                'factory_reference' => $l->catalogProduct?->factory_reference,
                'description' => $l->catalogProduct?->name,
                'quantity' => $l->quantity,
                'unit_cost_snapshot' => $l->unit_cost_snapshot,
                'subtotal' => $l->subtotal(),
            ])->values(),
        ];
    }

    private function userName(?User $user): ?string
    {
        return $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
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
