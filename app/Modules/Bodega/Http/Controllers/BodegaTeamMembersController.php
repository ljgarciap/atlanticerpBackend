<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Services\BodegaHomeService;
use App\Modules\Bodega\Support\BodegaRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fix de regresión cruzada (2026-07-28) — antes de este endpoint, `GET /bodega/home/team`
 * (SCRUM-365/366) era el ÚNICO listado de personas seleccionables de Bodega que tenía el
 * frontend; al restringirlo a solo Asistentes (fix correcto para el selector de Home), se rompió
 * silenciosamente todo lo demás que lo reusaba: "Asignar Repartidor" (SCRUM-396) y "Quién
 * entregó" (SCRUM-399) en el tablero de Pedidos necesitan usuarios `COURIER`, "Asignar Picker"
 * (SCRUM-383, ya en producción) necesita usuarios `PICKER`.
 *
 * Endpoint genérico fuera del namespace `home` a propósito — no es un panel del Home, es un
 * selector de personas reusable por cualquier pantalla de Bodega. `GET /bodega/home/team` sigue
 * existiendo sin cambio de contrato (wrapper sobre `BodegaHomeService::team()`, que a su vez
 * delega acá con `role=asistente_bodega` fijo).
 *
 * Contrato: `GET /bodega/team-members?role={asistente_bodega|courier|picker}` — ver
 * `BodegaRoles::TEAM_MEMBERS_QUERY_MAP` para los valores válidos. Respuesta 200:
 * `{"data": [{"id": int, "name": string}, ...]}`, mismo shape que `/bodega/home/team` para que el
 * frontend no tenga que remapear tipos. `role` ausente/inválido → 422
 * `{"message": string, "errors": {"role": [string]}}`.
 */
final class BodegaTeamMembersController extends Controller
{
    public function __construct(private readonly BodegaHomeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $role = (string) $request->query('role', '');

        $roleKey = BodegaRoles::TEAM_MEMBERS_QUERY_MAP[$role] ?? null;
        if ($roleKey === null) {
            return response()->json([
                'message' => 'El parámetro role debe ser uno de: '.implode(', ', array_keys(BodegaRoles::TEAM_MEMBERS_QUERY_MAP)).'.',
                'errors'  => ['role' => ['Valor inválido.']],
            ], 422);
        }

        return response()->json(['data' => $this->service->teamMembers($roleKey) ?? collect()]);
    }
}
