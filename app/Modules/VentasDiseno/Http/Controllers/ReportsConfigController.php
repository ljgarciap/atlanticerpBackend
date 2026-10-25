<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\ReportSettingsRequest;
use App\Modules\VentasDiseno\Http\Requests\SalesGoalRequest;
use App\Modules\VentasDiseno\Models\ReportSettings;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Modules\VentasDiseno\Services\HomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-072/075/076/SCRUM-146 — sección "Configuración" de Reportes: Meta por
 * vendedor y parámetros de alerta/forecast. Gate único
 * ventas_diseno.reports.configure para todo lo que escribe (ver rutas); la
 * lectura de goals también queda gateada acá porque solo tiene sentido para
 * quien va a poder editarla, a diferencia de report_settings (leída por
 * cualquiera con ventas_diseno.read para poder mostrar "40%/70%" en Forecast).
 */
final class ReportsConfigController extends Controller
{
    public function __construct(private readonly HomeService $home) {}

    public function goalsIndex(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $goals = SalesGoal::with('user')->get()->keyBy('user_id');

        // Mismo mecanismo que HomeService::vendors() (role_id/RoleModuleVisibility) — no el
        // enum legado `user_roles` (`role='designer'`), que dejó de reflejar el roster real
        // desde que BusinessRoleUserSeeder migró a roles de negocio (ver ADR-006). Hallazgo
        // real de Pre-QA (2026-07-30, SCRUM-146): con el mecanismo viejo, ninguno de los 9
        // vendedor reales aparecía en esta pantalla, solo usuarios demo.
        $vendors = $this->home->vendors();

        return response()->json(['data' => $vendors->map(function (array $vendor) use ($goals) {
            $goal = $goals->get($vendor['id']);

            return [
                'user_id'          => $vendor['id'],
                'user_name'        => $vendor['name'],
                'goal_amount'      => $goal?->goal_amount,
                'goal_plus_amount' => $goal?->goal_plus_amount,
            ];
        })->values()]);
    }

    public function goalsUpsert(SalesGoalRequest $request, int $userId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $vendor = User::find($userId);
        if ($vendor === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{goal_amount: float, goal_plus_amount?: float|null} $validated */
        $validated = $request->validated();

        $goal = SalesGoal::updateOrCreate(['user_id' => $userId], $validated);

        return response()->json([
            'user_id' => $userId, 'user_name' => trim("{$vendor->first_name} {$vendor->last_name}"),
            'goal_amount' => $goal->goal_amount, 'goal_plus_amount' => $goal->goal_plus_amount,
        ]);
    }

    public function settingsShow(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->formatSettings(ReportSettings::current()));
    }

    public function settingsUpdate(ReportSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $settings = ReportSettings::current();
        $settings->update($request->validated());

        /** @var ReportSettings $fresh */
        $fresh = $settings->fresh();

        return response()->json($this->formatSettings($fresh));
    }

    /** @return array<string, mixed> */
    private function formatSettings(ReportSettings $settings): array
    {
        return [
            'low_sales_threshold'          => $settings->low_sales_threshold,
            'low_sales_months'             => $settings->low_sales_months,
            'quote_probability_percent'    => $settings->quote_probability_percent,
            'proposal_probability_percent' => $settings->proposal_probability_percent,
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
