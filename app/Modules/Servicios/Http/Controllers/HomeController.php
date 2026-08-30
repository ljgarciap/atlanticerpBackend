<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Modules\Servicios\Services\HomeService;
use App\Modules\Servicios\Services\InstallationGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-207/208/210/211) — pantalla "Inicio". Mismo patrón que
 * `BodegaHomeController`/`ComprasHomeController` (un único `summary()` con secciones anidadas).
 */
final class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $home,
        private readonly InstallationGoalService $goals,
    ) {}

    /** GET /api/servicios/home/summary */
    public function summary(): JsonResponse
    {
        return response()->json($this->home->summary());
    }

    /**
     * GET /api/servicios/home/estado-tickets — REQ-215. Query param `tipo` opcional (mismo
     * vocabulario que `Ticket::TIPOS`, validado por el filtro de `TicketService::board()`). Aparte
     * de `summary()` porque necesita este param, que el resto de las secciones no usa.
     */
    public function estadoTickets(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');

        return response()->json($this->home->estadoTickets(is_string($tipo) ? $tipo : null));
    }

    /**
     * PUT /api/servicios/home/installation-goal — REQ-211 RN2a(c), Gerencia sobrescribe la meta
     * de un mes puntual (RN: rol validado en la ruta — solo `role:superadmin,management`).
     */
    public function updateInstallationGoal(Request $request): JsonResponse
    {
        /** @var array{year: int, month: int, value: int} $validated */
        $validated = $request->validate([
            'year'  => ['required', 'integer', 'min:2020'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'value' => ['required', 'integer', 'min:0'],
        ]);

        $this->goals->setOverride($validated['year'], $validated['month'], $validated['value']);

        return response()->json($this->goals->goalForMonth($validated['year'], $validated['month']));
    }
}
