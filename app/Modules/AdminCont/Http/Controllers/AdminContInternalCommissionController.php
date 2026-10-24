<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Http\Requests\StoreCommissionTierRequest;
use App\Modules\AdminCont\Http\Requests\UpdateCommissionTierRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\CommissionTier;
use App\Modules\AdminCont\Services\CommissionExportService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\Auth\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-498→502) — Comisiones Internas. Ver
 * docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md.
 */
class AdminContInternalCommissionController
{
    public function __construct(
        private readonly CommissionOrderService $orders,
        private readonly CommissionExportService $export,
    ) {}

    /** GET /api/admin-contab/commissions/internal/tiers — REQ-498, solo lectura para todos. */
    public function tiers(): JsonResponse
    {
        return response()->json(
            CommissionTier::query()->orderBy('orden')->get(),
        );
    }

    /** POST /api/admin-contab/commissions/internal/tiers — CRUD gateado por `primary_approver_only` en routes. */
    public function storeTier(StoreCommissionTierRequest $request): JsonResponse
    {
        $tier = CommissionTier::create($request->validated());
        $this->resequenceTiersByMontoMinimo();

        return response()->json($tier->fresh(), 201);
    }

    /** PUT /api/admin-contab/commissions/internal/tiers/{id} */
    public function updateTier(UpdateCommissionTierRequest $request, int $id): JsonResponse
    {
        $tier = CommissionTier::find($id);
        if ($tier === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tier->update($request->validated());
        $this->resequenceTiersByMontoMinimo();

        return response()->json($tier->fresh());
    }

    /** DELETE /api/admin-contab/commissions/internal/tiers/{id} */
    public function destroyTier(int $id): JsonResponse
    {
        $tier = CommissionTier::find($id);
        if ($tier === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tier->delete();
        $this->resequenceTiersByMontoMinimo();

        return response()->json(null, 204);
    }

    /**
     * Rebote de QA (SCRUM-575, 2026-08-26): "Agregar tramo" dejaba el tramo nuevo al final de la
     * lista (`orden` asignado como `sorted.length + 1` en el frontend) en vez de ordenado por
     * monto mínimo. `orden` es el campo que de verdad gobierna el listado (`tiers()`) y el
     * desempate de `CommissionOrderService::percentFor()` — nunca se derivaba de `monto_minimo`.
     * Recalculamos `orden` de TODOS los tramos tras cualquier escritura, server-side, para que el
     * orden de la tabla sea siempre consistente sin depender de que el cliente lo mande bien.
     */
    private function resequenceTiersByMontoMinimo(): void
    {
        CommissionTier::query()
            ->orderBy('monto_minimo')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (CommissionTier $tier, int $index): void {
                $newOrden = $index + 1;
                if ($tier->orden !== $newOrden) {
                    $tier->update(['orden' => $newOrden]);
                }
            });
    }

    /**
     * GET /api/admin-contab/commissions/internal/vendors — opciones del selector de vendedor
     * (Contabilidad/Mark/Gerencia, REQ-500). Todo `vendedor_disenador`, tenga o no pedidos en el mes
     * filtrado — un vendedor sin pedidos este mes igual debe poder elegirse para revisar su
     * historial de meses anteriores.
     */
    public function vendorOptions(): JsonResponse
    {
        $vendedores = User::whereIn('id', $this->vendedorDisenadorIds())
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return response()->json($vendedores->map(fn (User $v) => [
            'id' => $v->id,
            'nombre' => trim("{$v->first_name} {$v->last_name}"),
        ])->values());
    }

    /**
     * Roster completo del rol `vendedor_disenador` — usado tanto por el selector (REQ-500) como
     * por la construcción de filas de `buildSummary()` (REQ-505, para que un vendedor sin ningún
     * pedido igual tenga una fila que expandir).
     *
     * @return Collection<int, int>
     */
    private function vendedorDisenadorIds(): Collection
    {
        $roleId = Role::where('key', 'vendedor_disenador')->value('id');

        return $roleId === null ? collect() : User::where('role_id', $roleId)->pluck('id');
    }

    /** GET /api/admin-contab/commissions/internal?mes=YYYY-MM&vendedor_id= — REQ-499/500/501/502. */
    public function summary(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->buildSummary($actor, $request));
    }

    /**
     * GET /api/admin-contab/commissions/internal/{vendedorId}/account-statement?mes=YYYY-MM —
     * REQ-507. Contabilidad/Mark/Gerencia de cualquier vendedor; un vendedor solo el propio, verificado
     * server-side (nunca solo ocultando el botón en la UI, mismo criterio que el resto del
     * módulo).
     */
    public function accountStatement(Request $request, int $vendedorId): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $actor->canViewTeamV2('admin_contab') && $actor->id !== $vendedorId) {
            return response()->json(['message' => 'No autorizado para ver el estado de cuenta de otro vendedor.'], 403);
        }

        $vendor = User::find($vendedorId);
        if ($vendor === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $mesParam = $request->string('mes')->toString();
        $parsedMonth = $mesParam !== '' ? CarbonImmutable::createFromFormat('Y-m', $mesParam) : null;
        $viewMonth = ($parsedMonth instanceof CarbonImmutable ? $parsedMonth : CarbonImmutable::now())->startOfMonth();

        return response()->json($this->orders->accountStatementForVendor($vendor, $viewMonth));
    }

    /**
     * GET .../{vendedorId}/account-statement/pdf?mes= — REQ-507, botón "Ver/Imprimir PDF" del
     * documento. Mismo gate de autorización que `accountStatement()`.
     */
    public function accountStatementExport(Request $request, int $vendedorId): Response
    {
        $actor = $this->resolveUser($request);

        if ($actor === null || (! $actor->canViewTeamV2('admin_contab') && $actor->id !== $vendedorId)) {
            abort(403, 'No autorizado para ver el estado de cuenta de otro vendedor.');
        }

        $vendor = User::find($vendedorId);
        if ($vendor === null) {
            abort(404);
        }

        $mesParam = $request->string('mes')->toString();
        $parsedMonth = $mesParam !== '' ? CarbonImmutable::createFromFormat('Y-m', $mesParam) : null;
        $viewMonth = ($parsedMonth instanceof CarbonImmutable ? $parsedMonth : CarbonImmutable::now())->startOfMonth();

        $data = $this->orders->accountStatementForVendor($vendor, $viewMonth);

        return $this->export->toAccountStatementPdf($data);
    }

    /** GET /api/admin-contab/commissions/internal/export?mes=&vendedor_id=&format=pdf|excel — REQ-498 RN2. */
    public function export(Request $request): Response|StreamedResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null || ! $actor->canViewTeamV2('admin_contab')) {
            // RN de REQ-498: cada vendedor consulta la tabla de tramos, pero no exporta el
            // reporte completo — exportar es exclusivo de Contabilidad/Mark/Gerencia (view_team).
            abort(403, 'No autorizado para exportar este reporte.');
        }

        $data = $this->buildSummary($actor, $request);
        // `format` (no `formato`) — mismo nombre que manda el frontend (`adminContabApi.ts`,
        // `commissionsInternal.export`). Corregido en Senior Review: la primera versión leía
        // `formato`, que nunca matcheaba, así que siempre exportaba PDF sin importar lo elegido.
        $format = $request->string('format', 'pdf')->toString();

        return $format === 'excel'
            ? $this->export->toExcel($data)
            : $this->export->toPdf($data);
    }

    /** @return array<string, mixed> */
    private function buildSummary(User $actor, Request $request): array
    {
        $canViewTeam = $actor->canViewTeamV2('admin_contab');

        $mesParam = $request->string('mes')->toString();
        $parsedMonth = $mesParam !== '' ? CarbonImmutable::createFromFormat('Y-m', $mesParam) : null;
        // createFromFormat() puede devolver null/false según la firma (DateTimeInterface) — mes
        // vacío o inválido cae al mes actual, nunca null/false hacia adelante.
        $viewMonth = ($parsedMonth instanceof CarbonImmutable ? $parsedMonth : CarbonImmutable::now())->startOfMonth();

        $vendedorIdParam = $request->filled('vendedor_id') ? (int) $request->integer('vendedor_id') : null;

        // Un vendedor sin view_team siempre queda auto-filtrado a sí mismo, sin importar qué
        // vendedor_id mande el cliente (RN de REQ-500/577).
        $vendorScopeId = $canViewTeam ? $vendedorIdParam : $actor->id;

        $allOrders = $this->orders->ordersForAllVendors();

        // REQ-505 (Batch 15) — un `vendedor_disenador` sin NINGÚN pedido en toda su historia (ej.
        // recién asignado, o simplemente sin actividad) sigue siendo seleccionable en el filtro
        // (`vendorOptions()` lista el rol completo), pero antes de este fix no aparecía como fila
        // en ningún mes/filtro — `vendorIds` salía únicamente de `allOrders`, así que ese vendedor
        // nunca tenía una fila que expandir para ver "Sin pedidos registrados todavía." (Escenario
        // 2 de REQ-505). Unimos el roster completo del rol con los ids que ya vienen de
        // `allOrders` (algún pedido puede estar asociado a un `owner_id` que no es
        // `vendedor_disenador` — dato preexistente de Batch 14, no se retira nada que ya se veía).
        $vendorIds = $canViewTeam
            ? $allOrders->pluck('vendedor_id')->unique()->merge($this->vendedorDisenadorIds())->unique()->values()
            : collect([$actor->id]);

        $tableVendorIds = $vendorIds;
        if ($vendorScopeId !== null) {
            $tableVendorIds = $tableVendorIds->filter(fn (int $id) => $id === $vendorScopeId)->values();
        }

        $vendedores = User::whereIn('id', $tableVendorIds)->orderBy('first_name')->get();

        $rows = $vendedores->map(fn (User $v) => $this->orders->summaryForVendor($v, $viewMonth))->values();

        // Rebote de QA (SCRUM-577, 2026-08-26) — RN3: el filtro de vendedor_id angosta la tabla de
        // vendedores, pero las 4 tarjetas de resumen del mes deben seguir mostrando el total sin
        // filtrar. Antes, las tarjetas sumaban $rows (ya angostado por $vendorScopeId), así que se
        // veían afectadas por el mismo filtro que la tabla. Recalculamos aparte solo cuando el
        // filtro de verdad angostó algo (evita una query extra en el caso común sin filtro).
        $cardRows = $tableVendorIds->count() === $vendorIds->count()
            ? $rows
            : User::whereIn('id', $vendorIds)->orderBy('first_name')->get()
                ->map(fn (User $v) => $this->orders->summaryForVendor($v, $viewMonth))->values();

        $mesesDisponibles = $allOrders
            ->pluck('mes_cierre')
            ->filter()
            ->push(CarbonImmutable::now()->startOfMonth())
            ->unique(fn (CarbonImmutable $m) => $m->toDateString())
            ->sort()
            ->map(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->values();

        // SCRUM-904 — el banner sigue la MISMA regla que $cardRows arriba (comentario "Rebote de
        // QA SCRUM-577"): se angosta únicamente cuando el actor no tiene team-view (auto-scope a
        // sí mismo), nunca por el filtro manual de vendedor_id que un admin aplique en el
        // dropdown — ese filtro es de la tabla, no de las tarjetas/banner.
        $banner = $this->orders->pendingAutoPayBanner($canViewTeam ? null : $actor->id);

        // Forma plana — coincide con `CommissionInternalSummary` (`types/adminContab.ts`) tal
        // como lo consume `ComisionesInternasPage.tsx`, no con una envoltura `tarjetas`/`banner`
        // aparte (gap de contrato encontrado en Senior Review — Backend/Frontend Dev
        // implementaron en paralelo sobre el mismo ADR, esperable, ver
        // feedback_worktree_isolation_one_repo_per_dispatch en la memoria del proyecto).
        return [
            'mes' => $viewMonth->format('Y-m'),
            'meses_disponibles' => $mesesDisponibles,
            'mes_cerrado' => $viewMonth->lt(CarbonImmutable::now()->startOfMonth()),
            'puede_ver_equipo' => $canViewTeam,
            'puede_exportar' => $canViewTeam,
            // Bug real de Pre-QA (Visual Review, 2026-08-25): el frontend gateaba el CRUD de
            // tramos (botones editar/eliminar/agregar en el modal "Tabla de comisión
            // escalonada") con `admin_contab.edit`, que Contabilidad también tiene — pero `POST/PUT/
            // DELETE .../tiers` está gateado por `primary_approver_only` en routes/admin-contab.php, así que
            // Contabilidad veía controles que siempre devolvían 403 al guardar. Mismo patrón ya
            // establecido en `AdminContInvoiceController::detail()` (`puede_decidir_incobrable`)
            // — el frontend nunca decide por su cuenta quién es Gerencia, el backend expone el
            // cómputo dependiente del actor en el endpoint de acceso general.
            'puede_editar_tramos' => $this->isPrimaryApprover($actor, $request),
            'total_pedidos_mes' => round((float) $cardRows->sum('total_pedidos_mes'), 2),
            'vendedores_con_pedidos_mes' => $cardRows->filter(fn (array $r) => $r['total_pedidos_mes'] > 0)->count(),
            'ya_pagada' => round((float) $cardRows->sum('pagada'), 2),
            'por_pagar' => round((float) $cardRows->sum('por_pagar'), 2),
            'pendiente_cobro' => round((float) $cardRows->sum('pendiente_cobro'), 2),
            'banner_comisiones_count' => $banner['count'],
            'banner_comisiones_total' => $banner['total'],
            'vendedores' => $rows,
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

    /** Mismo criterio que `EnsureIsPrimaryApprover` (el middleware `primary_approver_only` real que gatea
     *  storeTier/updateTier/destroyTier) y `AdminContInvoiceController::detail()` —
     *  `superadmin.all` bypassa, si no hay `primary_approver_user_id` configurado nadie es Gerencia. */
    private function isPrimaryApprover(User $actor, Request $request): bool
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        if (in_array('superadmin.all', $permissions, true)) {
            return true;
        }

        $primaryApproverId = AdminContFiscalSettings::current()->primary_approver_user_id;

        return $primaryApproverId !== null && $actor->id === $primaryApproverId;
    }
}
