<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\AdminCont\Services\PaymentService;
use App\Modules\AdminCont\Services\PendingAlertsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\RoleKeys;
use App\Shared\Support\ApprovalsPendingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch final de Admin&Cont — "Inicio" (SCRUM-503→512, REQ-426→435). Grupo 1 (SCRUM-504→508,
 * REQ-427→431) — panel "Resumen del mes": 5 tarjetas, todas reusando cómputos que ya existen en
 * otros módulos de Admin&Cont, nunca una fórmula paralela (ver docblock de cada método reusado).
 */
final class AdminContHomeController
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly CommissionOrderService $internalCommissions,
        private readonly ArchitectCommissionService $externalCommissions,
        private readonly PendingAlertsService $pendingAlerts,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    /** GET /api/admin-contab/home/resumen-mes — REQ-427→431. */
    public function resumenMes(): JsonResponse
    {
        return response()->json([
            'total_cobrado_mes'    => $this->payments->summaryForCurrentMonth()['total_cobrado'],
            'mes_label'            => $this->mesLabelActual(),
            'cuentas_al_dia'       => $this->invoices->cuentasAlDia(),
            'cartera_por_cobrar'   => $this->invoices->carteraPorCobrar(),
            'ventas_de_ayer'       => $this->invoices->ventasDeAyer(),
            'comisiones_por_pagar' => [
                'internas' => $this->internalCommissionsPorPagar(),
                // ArchitectCommissionService::summary()['pendiente'] NO existe a este nivel — ese
                // campo solo vive DENTRO de cada fila de `arquitectos` (comisión pendiente de ESE
                // arquitecto). El total agregado de toda la empresa es `pendiente_factura`,
                // devuelto por `buildCards()` al tope de `summary()` — mismo campo que ya
                // consume la tarjeta global de Comisiones Externas.
                'externas' => $this->externalCommissions->summary()['pendiente_factura'],
            ],
        ]);
    }

    /**
     * REQ-431 — comisiones internas pendientes de pago, TODOS los vendedores, sin scope de actor
     * (a diferencia de `AdminContInternalCommissionController::buildSummary()`, que auto-filtra a
     * `$actor` cuando no tiene `view_team`): esta tarjeta de Inicio es siempre el total de la
     * empresa, la vea quien la vea — Contabilidad/Asistente/Aprobador/Gerencia ya comparten el mismo roster
     * amplio que gatea toda la pantalla "Inicio".
     */
    private function internalCommissionsPorPagar(): float
    {
        $roleId = Role::where('key', RoleKeys::VENDEDOR)->value('id');
        if ($roleId === null) {
            return 0.0;
        }

        $viewMonth  = CarbonImmutable::now()->startOfMonth();
        $vendedores = User::where('role_id', $roleId)->get();

        $total = $vendedores->sum(
            fn (User $v) => $this->internalCommissions->summaryForVendor($v, $viewMonth)['por_pagar'],
        );

        return round((float) $total, 2);
    }

    /** GET /api/admin-contab/home/pendientes — REQ-433. */
    public function pendientes(): JsonResponse
    {
        return response()->json($this->pendingAlerts->alerts());
    }

    /** GET /api/admin-contab/home/vencidos-por-vencer — REQ-434. */
    public function vencidosPorVencer(): JsonResponse
    {
        return response()->json($this->invoices->vencidosYPorVencer());
    }

    /**
     * SCRUM-908 — GET /api/admin-contab/home/approvals-pending. Lista completa (sin truncar, el
     * frontend corta a 5 + "Ver más") de las aprobaciones pendientes de Admin&Cont para el usuario
     * logueado, ordenadas por antigüedad. Lista vacía (nunca 403) si no tiene ningún permiso de
     * aprobación de este módulo.
     */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'admin_contab')]);
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

    /** @return list<string> */
    private function jwtPermissions(Request $request): array
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        return is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];
    }

    private function mesLabelActual(): string
    {
        $nombres = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $now = now();

        return $nombres[(int) $now->format('n')] . ' ' . $now->format('Y');
    }
}
