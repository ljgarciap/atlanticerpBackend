<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Models\LiquidationAgency;
use App\Modules\Compras\Models\LiquidationAgencyContact;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-210 (REQ-147, alcance reducido) — buscador + alta de agencias aduanales/de liquidación,
 * para el picker "Liquidar" del detalle de una orden Zona Libre. Mismo patrón simple que
 * ApprovedProjectController — sin paginación, volumen esperado bajo (agencias, no órdenes).
 *
 * SCRUM-254/255/256 (REQ-191/192/193) — se agregan acá mismo (no un controller nuevo) porque
 * operan sobre el mismo recurso: resumen (REQ-191), detalle + pedidos asociados (REQ-193) y
 * gestión de contactos (REQ-192).
 *
 * ⚠️ REQ-191 pide también "situación de pago" (saldo pendiente con la agencia) en el resumen —
 * las RN1→RN3 del ticket no están visibles en el texto (solo "ver Reglas de Negocio del REQ-191",
 * sin detalle, ni el único escenario de aceptación del ticket lo ejercita) y no hay ninguna tabla
 * de pagos a agencias en el modelo de datos aprobado (`docs/architecture/
 * compras-sustitutos-pagos-agencias-reclamos-reportes.md` solo modela pagos A PROVEEDORES,
 * REQ-187→190).
 *
 * 2026-07-18 — Luis pidió mockear lo mejor posible en vez de dejarlo fuera de alcance (ver
 * comentario en SCRUM-254). Implementado como carga MANUAL de Compras sobre la propia agencia
 * (`pending_payment_amount`/`paid_amount`/`last_payment_date`/`next_payment_date`, ver migración
 * `add_payment_tracking_to_compras_liquidation_agencies_table`) — NO un historial de pagos con
 * comprobante como `purchase_order_payments`, porque eso implicaría inventar RN que nadie definió.
 * El saldo pendiente (`pending_payment_amount - paid_amount`) se deriva en el frontend, igual que
 * en el mockup de Gerencia. Si más adelante Analista define las RN reales, este mock se reemplaza.
 */
final class LiquidationAgencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        $query = LiquidationAgency::query()->orderBy('name');
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return response()->json([
            'data' => $query->limit(20)->get()->map(fn (LiquidationAgency $a) => $this->format($a))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email'        => ['nullable', 'email', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);

        $agency = LiquidationAgency::create($validated);

        return response()->json($this->format($agency), 201);
    }

    /**
     * SCRUM-254 (REQ-191) — carga manual de la "situación de pago" (mock, ver nota de alcance en
     * la clase). Nada más de la agencia es editable acá todavía (nombre/contacto no tenían update
     * antes de este cambio y no se pidió agregarlo); si se necesita, es un ticket aparte.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $agency = LiquidationAgency::find($id);
        if ($agency === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'pending_payment_amount' => ['required', 'numeric', 'min:0'],
            'paid_amount'            => ['required', 'numeric', 'min:0'],
            'last_payment_date'      => ['nullable', 'date'],
            'next_payment_date'      => ['nullable', 'date'],
        ]);

        $agency->update($validated);

        return response()->json($this->format($agency));
    }

    /** REQ-191 — tabla resumen, conteo liquidada/por liquidar por agencia + KPIs (ver nota de alcance arriba). */
    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $agencies = LiquidationAgency::query()->with('purchaseOrders.provider')->orderBy('name')->get();

        $rows = $agencies->map(fn (LiquidationAgency $a) => [
            'id'                     => $a->id,
            'name'                   => $a->name,
            'liquidated_count'       => $a->purchaseOrders->filter(
                fn (PurchaseOrder $o) => $o->isLiquidated()
            )->count(),
            'pending_count' => $a->purchaseOrders->filter(
                fn (PurchaseOrder $o) => ! $o->isLiquidated()
            )->count(),
            'pending_payment_amount' => (float) $a->pending_payment_amount,
            'paid_amount'            => (float) $a->paid_amount,
            'last_payment_date'      => $a->last_payment_date?->format('Y-m-d'),
            'next_payment_date'      => $a->next_payment_date?->format('Y-m-d'),
        ])->values();

        // SCRUM-754 — kpis siguen sobre $rows completo (sin filtros en esta pantalla); solo data pagina.
        $pagination = Pagination::params($request, [], 'name');
        $paginator  = Pagination::ofArray(array_values($rows->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values($paginator->items()),
            'meta' => Pagination::meta($paginator),
            'kpis' => [
                'agencies_with_pending_balance' => $rows->filter(
                    fn (array $r) => ($r['pending_payment_amount'] - $r['paid_amount']) > 0
                )->count(),
                'total_pending_amount' => $rows->sum('pending_payment_amount'),
                'total_paid_amount'    => $rows->sum('paid_amount'),
                'projects_pending_liquidation' => $rows->sum('pending_count'),
            ],
        ]);
    }

    /** REQ-193 — detalle de la agencia: contactos + pedidos asociados con su estado de liquidación. */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var LiquidationAgency|null $agency */
        $agency = LiquidationAgency::with(['contacts', 'purchaseOrders.provider'])->find($id);
        if ($agency === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            ...$this->format($agency),
            'contacts' => $agency->contacts->map(fn (LiquidationAgencyContact $c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
            ])->values(),
            'purchase_orders' => $agency->purchaseOrders->map(fn (PurchaseOrder $o) => [
                'id'                 => $o->id,
                'provider_name'      => $o->provider?->name,
                'status'             => $o->status,
                'total_amount'       => $o->total_amount,
                'currency'           => $o->currency,
                'liquidation_status' => $o->isLiquidated() ? 'liquidada' : 'por_liquidar',
            ])->values(),
        ]);
    }

    /** REQ-192 — agregar un contacto nuevo. */
    public function storeContact(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $agency = LiquidationAgency::find($id);
        if ($agency === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $contact = $agency->contacts()->create($validated);

        return response()->json($this->formatContact($contact), 201);
    }

    /** REQ-192 — editar un contacto existente. */
    public function updateContact(Request $request, int $id, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $contact = LiquidationAgencyContact::where('liquidation_agency_id', $id)->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $contact->update($validated);

        return response()->json($this->formatContact($contact));
    }

    /** REQ-192 RN2 — no se puede quitar el único contacto que le queda a una agencia. */
    public function destroyContact(Request $request, int $id, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $agency = LiquidationAgency::find($id);
        if ($agency === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact = $agency->contacts()->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($agency->contacts()->count() <= 1) {
            return response()->json(['message' => 'No se puede quitar el único contacto de la agencia.'], 422);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function formatContact(LiquidationAgencyContact $contact): array
    {
        return [
            'id'    => $contact->id,
            'name'  => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
        ];
    }

    /** @return array<string, mixed> */
    private function format(LiquidationAgency $agency): array
    {
        return [
            'id'                     => $agency->id,
            'name'                   => $agency->name,
            'contact_name'           => $agency->contact_name,
            'phone'                  => $agency->phone,
            'email'                  => $agency->email,
            'notes'                  => $agency->notes,
            'pending_payment_amount' => (float) $agency->pending_payment_amount,
            'paid_amount'            => (float) $agency->paid_amount,
            'last_payment_date'      => $agency->last_payment_date?->format('Y-m-d'),
            'next_payment_date'      => $agency->next_payment_date?->format('Y-m-d'),
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
