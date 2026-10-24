<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Http\Controllers;

use App\Models\User;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use App\Modules\Gerencia\Services\GerenciaReportExportService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Shared\Support\ApprovalsPendingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SCRUM-156 — Pantalla de inicio del módulo Gerencia.
 *
 * GET /api/gerencia/home
 *   ?desde=YYYY-MM-DD  (default: inicio del mes actual)
 *   ?hasta=YYYY-MM-DD  (default: hoy)
 *   ?vendedor_id=int
 *   ?cliente_id=int
 */
final class GerenciaHomeController extends Controller
{
    public function __construct(
        private readonly GerenciaHomeService $service,
        private readonly GerenciaReportExportService $export,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'desde'       => 'nullable|date_format:Y-m-d',
            'hasta'       => 'nullable|date_format:Y-m-d|after_or_equal:desde',
            'vendedor_id' => 'nullable|integer|min:1',
            'cliente_id'  => 'nullable|integer|min:1',
        ]);

        $filters = array_filter($validated, fn (mixed $v): bool => $v !== null);

        return response()->json($this->service->home($filters, $user->id));
    }

    /**
     * GET /api/gerencia/home/export?desde&hasta&vendedor_id&cliente_id&format=pdf|excel
     * SCRUM-862 — mismos filtros que home(), reusa exactamente el mismo payload calculado por
     * GerenciaHomeService::home() (regla del módulo: nunca reimplementar una métrica en paralelo).
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'desde'       => 'nullable|date_format:Y-m-d',
            'hasta'       => 'nullable|date_format:Y-m-d|after_or_equal:desde',
            'vendedor_id' => 'nullable|integer|min:1',
            'cliente_id'  => 'nullable|integer|min:1',
            'format'      => 'required|string|in:pdf,excel',
        ]);

        $format  = $validated['format'];
        $filters = array_filter(
            array_intersect_key($validated, array_flip(['desde', 'hasta', 'vendedor_id', 'cliente_id'])),
            fn (mixed $v): bool => $v !== null,
        );

        $homeData = $this->service->home($filters, $user->id);

        // "Sin datos" (RN del ticket: no generar un archivo vacío sin explicación) — ninguna
        // factura ni proyecto cerrado en el rango filtrado, las 2 secciones que de verdad dependen
        // del rango de fechas.
        $facturasCount     = $homeData['facturacion']['kpis']['facturas_count'] ?? 0;
        $cerradosMesCount  = $homeData['proyectos']['kpis']['cerrados_mes_count'] ?? 0;
        if ($facturasCount === 0 && $cerradosMesCount === 0) {
            return response()->json(['empty' => true, 'message' => 'No hay datos para el rango y filtros seleccionados.']);
        }

        $vendorName = null;
        if (isset($filters['vendedor_id'])) {
            $vendor = User::find((int) $filters['vendedor_id']);
            $vendorName = $vendor !== null ? trim("{$vendor->first_name} {$vendor->last_name}") : null;
        }

        $clientName = null;
        if (isset($filters['cliente_id'])) {
            $client = MasterClient::find((int) $filters['cliente_id']);
            $clientName = $client?->name;
        }

        $filterLabels = [
            'desde'    => $filters['desde'] ?? now()->startOfMonth()->toDateString(),
            'hasta'    => $filters['hasta'] ?? now()->toDateString(),
            'vendedor' => $vendorName,
            'cliente'  => $clientName,
        ];

        $bytes    = $format === 'pdf' ? $this->export->toPdf($homeData, $filterLabels) : $this->export->toExcel($homeData, $filterLabels);
        $filename = 'reporte-gerencia-'.now()->format('Ymd-His').($format === 'pdf' ? '.pdf' : '.xlsx');
        $mime     = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => $mime]);
    }

    /**
     * GET /api/gerencia/vendors
     *
     * Lista de vendedores (misma lógica que Ventas & Diseño HomeService::vendors()).
     */
    public function vendors(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $roleIds = RoleModuleVisibility::where('module', 'ventas_diseno')
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->where('can_view_team', false)
            ->pluck('role_id');

        $vendors = User::whereIn('role_id', $roleIds)
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => [
                'id'   => $u->id,
                'name' => trim("{$u->first_name} {$u->last_name}"),
            ])
            ->values();

        return response()->json($vendors);
    }

    /**
     * GET /api/gerencia/clients?search=foo
     *
     * Lista de clientes (MasterClient) con búsqueda opcional.
     */
    public function clients(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = $request->input('search', '');

        $clients = MasterClient::query()
            ->when($search, fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->select('id', 'name')
            ->orderBy('name')
            ->limit(50)
            ->get();

        return response()->json($clients);
    }

    /**
     * SCRUM-908 — GET /api/gerencia/home/approvals-pending. Siempre vacío: Gerencia no tiene
     * ningún tipo de aprobación propio, existe solo para que los 6 módulos sean consistentes. La
     * vista real de Gerencia es `approvalsPendingAll()`, abajo.
     */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'gerencia')]);
    }

    /**
     * SCRUM-908 — GET /api/gerencia/home/approvals-pending-all. Ampliación de alcance aprobada por
     * Luis: vista consolidada de aprobaciones pendientes de TODOS los módulos que este usuario
     * puede decidir (no solo lo propio de Gerencia, que no tiene ninguna).
     */
    public function approvalsPendingAll(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->crossModuleForGerencia($user, $this->jwtPermissions($request))]);
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
}
