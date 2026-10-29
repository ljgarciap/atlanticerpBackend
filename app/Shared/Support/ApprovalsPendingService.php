<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;
use App\Modules\AdminCont\Models\ArchitectCommission;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Support\Collection;

/**
 * SCRUM-908 — agregador de "Aprobaciones Pendientes" para el Home de cada módulo. Cada método
 * `xxxItems()` reusa la MISMA condición de "pendiente" y, donde existe, la MISMA autorización que
 * ya usa el endpoint real de decisión de ese tipo (nunca reinventa la regla de permiso) — ver
 * inventario y fuentes en el comentario de PM de SCRUM-908 en Jira.
 *
 * Para los 5 tipos que ya cubre `AprobacionGate` (purchase_order/relocation/adjustment/
 * general_count/zona_libre, ver `GerenciaHomeService::pendingApprovalsSection()`):
 *
 * Senior Review SCRUM-908 (corregido tras el primer commit de Backend Dev) — el diseño original
 * asumía que el permiso nativo del módulo dueño (`bodega.approve`/`compras.approve`) era un
 * camino INDEPENDIENTE de decisión, en unión con `AprobacionGate::canAct()`. Verificado contra los
 * controllers reales: para purchase_order/relocation/adjustment/general_count esos permisos son
 * SOLO el gate grueso de la ruta — `RelocationRequestController::resolveApproverAccess()`,
 * `AdjustmentRequestController`, `GeneralCountController` y `PurchaseOrderController::approve()`
 * delegan la decisión real 100% a `AprobacionGate`/`primary_approver_user_id` (`compras.approve`
 * no es Gerencia, ver docblock de `AprobacionGate::esAprobador()`). Usar la unión ahí mostraba items a
 * usuarios que después recibían 403/422 al intentar actuar — por eso esos 4 usan SOLO
 * `$this->gate->canAct()`, sin el permiso nativo.
 *
 * `zona_libre` es la EXCEPCIÓN real (mantiene la unión, ver `zonaLibreItems()`/
 * `canActOnGateType()`): `ComprasZonaLibreRequestController::approve()` autoriza por el permiso
 * v2 del JWT (`compras.zona-libre.approve`) directamente, sin pasar por `AprobacionGate` — pero
 * `AprobacionGate::esAprobador()`/`canAct('zona_libre')` chequea ese mismo permiso vía
 * `$user->hasPermission()` (v1). Por el gap v1/v2 ya documentado en SCRUM-818, un usuario puede
 * tener el permiso real en el JWT (v2) sin tenerlo en v1 — acá sí hace falta la unión para no
 * ocultarle su propia aprobación pendiente.
 */
final class ApprovalsPendingService
{
    public function __construct(
        private readonly AprobacionGate $gate,
        private readonly GerenciaHomeService $gerenciaHome,
    ) {
    }

    /**
     * @param list<string> $jwtPermissions
     * @return list<array{tipo: string, label: string, created_at: string, detail_route: string}>
     */
    public function forModule(User $user, array $jwtPermissions, string $modulo): array
    {
        $items = match ($modulo) {
            // Senior Review SCRUM-908 — cada cadena arranca en `collect()` (Support\Collection
            // plana), nunca en el resultado crudo del primer resolver. Bug real encontrado
            // escribiendo tests: `Eloquent\Collection::map()` solo se degrada a `toBase()` (plana)
            // cuando el resultado tiene ≥1 item — un `->get()->map()` VACÍO se queda como
            // `Eloquent\Collection` vacía. Si un resolver temprano de la cadena viene vacío
            // (se queda Eloquent) y uno posterior viene con datos (ya degradado a plana),
            // `Eloquent\Collection::merge()` — que llama `$item->getKey()` sobre cada item de la
            // colección que se le pasa — revienta con "undefined method getKey()" porque
            // `ApprovalPendingItem` no es un Model. Arrancar en `collect()` fuerza que TODA la
            // cadena use `Support\Collection::merge()` (simple, sin `getKey()`), sin importar qué
            // tan vacíos o llenos vengan los resolvers intermedios.
            'admin_contab' => collect()
                ->merge($this->uncollectibleInvoiceItems($user, $jwtPermissions))
                ->merge($this->creditNoteDecisionItems($user, $jwtPermissions))
                ->merge($this->architectCommissionItems($user, $jwtPermissions))
                ->merge($this->cashCountApprovalItems($user, $jwtPermissions))
                ->merge($this->pettyCashReportItems($user, $jwtPermissions)),
            'bodega' => collect()
                ->merge($this->relocationItems($user, $jwtPermissions))
                ->merge($this->adjustmentItems($user, $jwtPermissions))
                ->merge($this->generalCountItems($user, $jwtPermissions)),
            'compras' => collect()
                ->merge($this->zonaLibreItems($user, $jwtPermissions))
                ->merge($this->purchaseOrderItems($user, $jwtPermissions))
                ->merge($this->purchaseOrderAmountChangeItems($user, $jwtPermissions))
                ->merge($this->replacementRequestItems($user, $jwtPermissions)),
            'servicios' => $this->serviceQuoteItems($user, $jwtPermissions),
            // Ventas & Diseño no tiene ningún tipo de aprobación propio en el inventario de
            // SCRUM-908 — endpoint existe para consistencia (los 6 módulos lo exponen), siempre
            // vacío hasta que exista uno.
            'ventas_diseno' => collect(),
            // Gerencia no tiene ningún tipo de aprobación PROPIO — agrega los de otros módulos vía
            // `crossModuleForGerencia()` (GET /api/gerencia/home/approvals-pending-all), no acá.
            'gerencia' => collect(),
            default => collect(),
        };

        return $this->sorted($items);
    }

    /**
     * SCRUM-908 — vista consolidada SOLO Gerencia (`GET /api/gerencia/home/approvals-pending-all`),
     * ampliación de alcance aprobada por Luis: une los tipos nativos que Gerencia puede decidir
     * directamente (Admin&Cont completo, Servicios, sustitutos, cambio de monto en OC, alta de
     * usuario) con los 5 tipos que YA agrega `GerenciaHomeService::pendingApprovalsSection()`
     * (reusado tal cual, no reimplementado, para heredar gratis el soporte de `ReglaAprobacion`).
     *
     * @param list<string> $jwtPermissions
     * @return list<array{tipo: string, label: string, created_at: string, detail_route: string}>
     */
    public function crossModuleForGerencia(User $user, array $jwtPermissions): array
    {
        // Ver docblock del fix en forModule() — misma razón, arranca en collect() plano.
        $native = collect()
            ->merge($this->uncollectibleInvoiceItems($user, $jwtPermissions))
            ->merge($this->creditNoteDecisionItems($user, $jwtPermissions))
            ->merge($this->architectCommissionItems($user, $jwtPermissions))
            ->merge($this->cashCountApprovalItems($user, $jwtPermissions))
            ->merge($this->pettyCashReportItems($user, $jwtPermissions))
            ->merge($this->serviceQuoteItems($user, $jwtPermissions))
            ->merge($this->replacementRequestItems($user, $jwtPermissions))
            ->merge($this->purchaseOrderAmountChangeItems($user, $jwtPermissions));

        $delegated = collect($this->gerenciaHome->pendingApprovalsForUser($user->id)['aprobaciones'])
            ->map(fn (array $a): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: (string) $a['type'],
                label: (string) $a['description'],
                createdAt: \Carbon\Carbon::parse((string) $a['created_at']),
                detailRoute: (string) $a['action_url'],
            ));

        return $this->sorted($native->merge($delegated));
    }

    // ── Admin&Cont ────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function uncollectibleInvoiceItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->isApproverAdminCont($user, $jwtPermissions)) {
            return collect();
        }

        return AdminContInvoice::where('incobrable_pendiente', true)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdminContInvoice $i): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'invoice_uncollectible',
                label: "Incobrable pendiente de decisión — Factura #{$i->order_id}",
                createdAt: $i->created_at,
                detailRoute: '/admin-contab/facturacion',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function creditNoteDecisionItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->isApproverAdminCont($user, $jwtPermissions)) {
            return collect();
        }

        return AdminContCreditNote::where('estado', AdminContCreditNote::ESTADO_PENDIENTE_APROBACION)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdminContCreditNote $n): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'credit_note_decision',
                label: "Nota de crédito pendiente de aprobación — NC-{$n->id}",
                createdAt: $n->created_at,
                detailRoute: '/admin-contab/notas-credito',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function architectCommissionItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->isApproverAdminCont($user, $jwtPermissions)) {
            return collect();
        }

        return ArchitectCommission::whereNotNull('porcentaje_pendiente_propuesto_por')
            ->orderBy('porcentaje_pendiente_propuesto_at')
            ->get()
            ->map(fn (ArchitectCommission $c): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'architect_commission_percent',
                label: '% de comisión propuesto pendiente de decisión',
                createdAt: $c->porcentaje_pendiente_propuesto_at ?? $c->created_at,
                detailRoute: '/admin-contab/comisiones/externas',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function cashCountApprovalItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->isApproverAdminCont($user, $jwtPermissions)) {
            return collect();
        }

        return AdminContDailyCashCount::where('estado', AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdminContDailyCashCount $c): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'cash_count_approval',
                label: "Arqueo de caja pendiente de aprobación — #{$c->numero}",
                createdAt: $c->created_at,
                detailRoute: '/admin-contab/arqueo-caja',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function pettyCashReportItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->isApproverAdminCont($user, $jwtPermissions)) {
            return collect();
        }

        return AdminContPettyCashReport::where('estado', AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdminContPettyCashReport $r): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'petty_cash_report',
                label: "Reporte de Caja Chica pendiente de aprobación — #{$r->numero}",
                createdAt: $r->created_at,
                detailRoute: '/admin-contab/caja-chica',
            ));
    }

    /** @param list<string> $jwtPermissions */
    private function isApproverAdminCont(User $user, array $jwtPermissions): bool
    {
        if (in_array('superadmin.all', $jwtPermissions, true)) {
            return true;
        }

        $approverId = AdminContFiscalSettings::current()->primary_approver_user_id;

        return PrimaryApprovers::isApprover($user->id, $approverId);
    }

    // ── Bodega / Compras (tipos ya cubiertos por AprobacionGate) ────────────────────

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function relocationItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->gate->canAct($user, 'relocation')) {
            return collect();
        }

        return RelocationRequest::where('estado', 'pendiente')
            ->orderBy('created_at')
            ->get()
            ->map(fn (RelocationRequest $r): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'relocation',
                label: 'Solicitud de reubicación de inventario',
                createdAt: $r->created_at,
                // SCRUM-1154 — las solicitudes de reubicación se gestionan en Bodega → Bodegas → «Solicitudes de reubicación»
                // (modal), no en Inventario. `relocation={id}` abre esa bandeja con esta solicitud resaltada.
                detailRoute: "/bodega/bodegas?relocation={$r->id}",
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function adjustmentItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->gate->canAct($user, 'adjustment')) {
            return collect();
        }

        return AdjustmentRequestLine::where('estado', 'Pendiente')
            ->orderBy('created_at')
            ->get()
            ->map(fn (AdjustmentRequestLine $a): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'adjustment',
                label: 'Ajuste de inventario pendiente',
                createdAt: $a->created_at,
                detailRoute: '/bodega/solicitud-ajuste',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function generalCountItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->gate->canAct($user, 'general_count')) {
            return collect();
        }

        return GeneralCountRequest::where('estado', 'pendiente_aprobacion')
            ->orderBy('created_at')
            ->get()
            ->map(fn (GeneralCountRequest $c): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'general_count',
                label: 'Conteo general pendiente de aprobación',
                createdAt: $c->created_at,
                detailRoute: '/bodega/inventario-general',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function zonaLibreItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->canActOnGateType($user, $jwtPermissions, 'zona_libre', 'compras.zona-libre.approve')) {
            return collect();
        }

        return BodegaZonaLibreRequest::where('status', 'pendiente')
            ->orderBy('created_at')
            ->get()
            ->map(fn (BodegaZonaLibreRequest $z): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'zona_libre',
                label: 'Solicitud Zona Libre pendiente',
                createdAt: $z->created_at,
                detailRoute: '/bodega/ordenes-zona-libre',
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function purchaseOrderItems(User $user, array $jwtPermissions): Collection
    {
        if (! $this->gate->canAct($user, 'purchase_order')) {
            return collect();
        }

        return PurchaseOrder::where('status', PurchaseOrder::STATUS_POR_APROBAR)
            ->where('requires_primary_approval', true)
            ->whereNull('approved_by')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PurchaseOrder $o): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'purchase_order',
                label: 'Orden de compra por ' . number_format((float) $o->total_amount, 2),
                createdAt: $o->created_at,
                detailRoute: "/compras/ordenes/{$o->id}",
            ));
    }

    /** @param list<string> $jwtPermissions */
    private function canActOnGateType(User $user, array $jwtPermissions, string $tipo, string $nativePermission): bool
    {
        if (in_array('superadmin.all', $jwtPermissions, true) || in_array($nativePermission, $jwtPermissions, true)) {
            return true;
        }

        return $this->gate->canAct($user, $tipo);
    }

    // ── Compras (sin AprobacionGate) ────────────────────────────────────────────────

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function purchaseOrderAmountChangeItems(User $user, array $jwtPermissions): Collection
    {
        if (! in_array('superadmin.all', $jwtPermissions, true) && ! in_array('compras.edit', $jwtPermissions, true)) {
            return collect();
        }

        // Senior Review SCRUM-908 — bug real encontrado escribiendo tests: `pending_amount_change`
        // vive en `purchase_orders` (seteado por `PurchaseOrderPaymentController::requestAmountChange()`
        // sobre `$order`), nunca existió en `purchase_order_payments`. La query original consultaba
        // el modelo equivocado — rompía con "column does not exist" para cualquier usuario con
        // `compras.edit` real. Sin timestamp propio de "cuándo se pidió el cambio" en el modelo
        // (solo `pending_amount_change`/`amount_change_requested_by`), se usa `updated_at` como
        // proxy de antigüedad — se toca exactamente en ese `update()`.
        return PurchaseOrder::whereNotNull('pending_amount_change')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (PurchaseOrder $o): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'purchase_order_amount_change',
                label: "Cambio de monto pendiente de aprobación — OC-{$o->id}",
                createdAt: $o->updated_at,
                detailRoute: "/compras/ordenes/{$o->id}",
            ));
    }

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function replacementRequestItems(User $user, array $jwtPermissions): Collection
    {
        $isFullApprover = in_array('superadmin.all', $jwtPermissions, true) || in_array('gerencia.view', $jwtPermissions, true);
        $ownProjectIds = $isFullApprover ? [] : PipelineCard::projectIdsOwnedBy($user);

        if (! $isFullApprover && $ownProjectIds === []) {
            return collect();
        }

        $query = ReplacementRequest::whereIn('status', [ReplacementRequest::STATUS_PENDIENTE, ReplacementRequest::STATUS_PENDIENTE_GERENCIA]);

        if (! $isFullApprover) {
            // Mismo criterio que ReplacementRequestController::canDecide(): sin gerencia.view,
            // solo ve lo "pendiente" (no "pendiente_gerencia") de un proyecto propio.
            $query->where('status', ReplacementRequest::STATUS_PENDIENTE)->whereIn('sales_project_id', $ownProjectIds);
        }

        return $query->orderBy('created_at')
            ->get()
            ->map(fn (ReplacementRequest $r): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'replacement_request',
                label: 'Solicitud de sustitución pendiente',
                createdAt: $r->created_at,
                detailRoute: '/compras/sustitutos',
            ));
    }

    // ── Servicios ────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $jwtPermissions
     * @return Collection<int, ApprovalPendingItem>
     */
    private function serviceQuoteItems(User $user, array $jwtPermissions): Collection
    {
        if (! in_array('superadmin.all', $jwtPermissions, true) && $user->role?->key !== RoleKeys::LIDER_SERVICIOS) {
            return collect();
        }

        return ServiceQuote::where('estado', ServiceQuote::ESTADO_SENT)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ServiceQuote $q): ApprovalPendingItem => new ApprovalPendingItem(
                tipo: 'service_quote',
                label: 'Cotización de servicio pendiente de decisión',
                createdAt: $q->created_at,
                detailRoute: '/servicios/cotizaciones',
            ));
    }

    // ── Auth ─────────────────────────────────────────────────────────────────────

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * @param Collection<int, ApprovalPendingItem> $items
     * @return list<array{tipo: string, label: string, created_at: string, detail_route: string}>
     */
    private function sorted(Collection $items): array
    {
        return array_values($items->sortBy(fn (ApprovalPendingItem $i): string => $i->createdAt->toIso8601String())
            ->map(fn (ApprovalPendingItem $i): array => $i->toArray())
            ->all());
    }
}
