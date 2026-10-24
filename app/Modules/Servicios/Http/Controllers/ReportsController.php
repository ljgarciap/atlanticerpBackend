<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Services\CommissionCaptureService;
use App\Modules\Servicios\Services\ReportsService;
use App\Shared\Http\Pagination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, pantalla "Reportes" (SCRUM-350→356, REQ-280→286). 7 endpoints GET de solo
 * lectura, sin escritura — mismo patrón que `HomeController` (un método por endpoint,
 * `response()->json(...)`, sin lógica de negocio en el controller).
 *
 * Selector de período (decisión de producto agregada tras revisar el mockup real, ver docblock
 * de `ReportsService`) — `resolveMonthPeriod()`/`resolveYearPeriod()` validan `year`/`month` con
 * el mismo patrón ya usado en `HomeController::updateInstallationGoal()`
 * (`$request->validate([...])`, 422 automático de Laravel si no matchea). `biblioteca()` es la
 * única excepción a propósito — no acepta período (REQ-286 es atemporal).
 */
final class ReportsController extends Controller
{
    private const MIN_YEAR = 2020;

    public function __construct(
        private readonly ReportsService $reports,
        private readonly CommissionCaptureService $commission,
    ) {}

    /** GET /api/servicios/reportes/panorama-mes — REQ-280. */
    public function panoramaMes(Request $request): JsonResponse
    {
        $period = $this->resolveMonthPeriod($request);

        return response()->json($this->reports->panoramaMes($period['year'], $period['month']));
    }

    /** GET /api/servicios/reportes/instalaciones-cotizadas-vs-realizadas — REQ-281. */
    public function instalacionesCotizadasVsRealizadas(Request $request): JsonResponse
    {
        $period = $this->resolveMonthPeriod($request);

        return response()->json($this->reports->instalacionesCotizadasVsRealizadas($period['year'], $period['month']));
    }

    /** GET /api/servicios/reportes/distribucion-tipo — REQ-282. */
    public function distribucionTipo(Request $request): JsonResponse
    {
        $period = $this->resolveMonthPeriod($request);

        return response()->json(['data' => $this->reports->distribucionTipo($period['year'], $period['month'])]);
    }

    /** GET /api/servicios/reportes/distribucion-tecnico — REQ-283. */
    public function distribucionTecnico(Request $request): JsonResponse
    {
        $period = $this->resolveMonthPeriod($request);

        return response()->json(['data' => $this->reports->distribucionTecnico($period['year'], $period['month'])]);
    }

    /**
     * GET /api/servicios/reportes/completados-anio — REQ-284. Solo `year` (sin `month` — RN1 ya
     * decide cuántos meses trae según sea el año en curso o uno pasado, ver `ReportsService`).
     */
    public function completadosAnio(Request $request): JsonResponse
    {
        $year = $this->resolveYearPeriod($request);

        return response()->json(['data' => $this->reports->completadosAnio($year)]);
    }

    /**
     * GET /api/servicios/reportes/comision-tecnico — REQ-285. RN1: el desglose viene
     * exclusivamente de `CommissionCaptureService::commissionForTechnician()` (REQ-258), nunca de
     * un cálculo paralelo. RN2: gate de visibilidad — SOLO Tecnico Servicios (el propio técnico) y Gerencia
     * (`superadmin`/`management`), replicado a propósito idéntico al de
     * `InternalTechnicianController::commission()` en vez de reusarlo directamente, porque acá no
     * hay `{id}` en la URL (el ticket es específico a Tecnico Servicios, no genérico a "un técnico").
     *
     * El técnico se resuelve por `has_bonus_plan = true` — hoy Tecnico Servicios Test es, por diseño
     * (`InternalTechnicianSeeder`), el único `InternalTechnician` con plan de bonificación activo;
     * no se hardcodea su nombre/email para no acoplar el endpoint a un dato de seed.
     */
    public function comisionTecnico(Request $request): JsonResponse
    {
        $technician = InternalTechnician::where('has_bonus_plan', true)->first();
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $roleKey = $user->role?->key;
        $esGerencia = $roleKey === 'superadmin' || $roleKey === 'management';
        $esElMismo = $technician->user_id !== null && $technician->user_id === $user->id;

        if (! $esGerencia && ! $esElMismo) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $year = $request->query('year');
        $month = $request->query('month');

        $data = $this->commission->commissionForTechnician(
            $technician,
            is_numeric($year) ? (int) $year : null,
            is_numeric($month) ? (int) $month : null,
        );

        return response()->json(['data' => $data]);
    }

    /** GET /api/servicios/reportes/biblioteca — REQ-286. */
    public function biblioteca(Request $request): JsonResponse
    {
        $pagination = Pagination::params($request, ['fecha'], 'fecha', 'desc');

        /** @var array{search?: string, tipo_documento?: string, estado?: string, per_page?: int, page?: int} $filters */
        $filters = array_filter([
            'search' => $request->query('search'),
            'tipo_documento' => $request->query('tipo_documento'),
            'estado' => $request->query('estado'),
            'per_page' => $pagination['per_page'],
            'page' => $pagination['page'],
        ], static fn (mixed $value): bool => $value !== null);

        $paginator = $this->reports->biblioteca($filters);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => Pagination::meta($paginator),
        ]);
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

    /**
     * `year`/`month` opcionales — default al mes/año en curso si se omiten (nunca se aplica un
     * default parcial: si se manda uno se puede omitir el otro, cada uno cae a su propio actual).
     * 422 automático de Laravel (`ValidationException`) si vienen pero no matchean el rango.
     *
     * @return array{year: int, month: int}
     */
    private function resolveMonthPeriod(Request $request): array
    {
        $now = Carbon::now('America/Panama');

        // `validate()` NUNCA castea query params a int (llegan como string desde $_GET, la regla
        // "integer" solo valida el formato) — con `declare(strict_types=1)` en este archivo, pasar
        // ese string a un parámetro `?int` revienta con TypeError. Cast explícito acá, una sola vez.
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:'.self::MIN_YEAR, 'max:'.($now->year + 1)],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        return [
            'year' => isset($validated['year']) ? (int) $validated['year'] : (int) $now->year,
            'month' => isset($validated['month']) ? (int) $validated['month'] : (int) $now->month,
        ];
    }

    /** Mismo criterio que `resolveMonthPeriod()`, sin `month` — usado solo por `completadosAnio()`. */
    private function resolveYearPeriod(Request $request): int
    {
        $now = Carbon::now('America/Panama');

        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:'.self::MIN_YEAR, 'max:'.($now->year + 1)],
        ]);

        return isset($validated['year']) ? (int) $validated['year'] : (int) $now->year;
    }
}
