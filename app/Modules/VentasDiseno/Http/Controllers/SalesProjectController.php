<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreSalesProjectRequest;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-027: buscar o crear un Proyecto (sales_project) acotado a un Subcliente ya
 * elegido, desde el formulario de Cotización. Reusa la misma tabla sales_projects
 * que ya usa Pipeline — no se duplica la entidad Proyecto.
 */
final class SalesProjectController extends Controller
{
    /**
     * SCRUM-734 (RN5.1) — este buscador alimenta únicamente el picker de Proyecto
     * del formulario de "+ Nueva cotización" (`QuotePage.tsx`, confirmado único
     * consumidor del endpoint). Un proyecto con al menos 1 cotización asociada
     * (Borrador o generada) ya no puede volver a cotizarse desde cero acá — la
     * vía correcta es "Usar como base para nueva versión" (ver
     * QuoteService::duplicate()). No afecta la precarga desde una tarjeta de
     * Pipeline existente (`from_pipeline_card_id` en QuoteController::store()),
     * que resuelve el proyecto directo, sin pasar por este buscador.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClientId = $request->query('sub_client_id');
        if (! is_numeric($subClientId)) {
            return response()->json(['message' => 'sub_client_id es requerido'], 422);
        }

        $query = SalesProject::query()
            ->where('sub_client_id', (int) $subClientId)
            ->whereDoesntHave('quotes')
            ->orderBy('name');

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $projects = $query->limit(20)->get();

        return response()->json([
            'data' => $projects->map(fn (SalesProject $p) => $this->format($p))->values(),
        ]);
    }

    /**
     * SCRUM-734 (RN5.3) — si el usuario escribe directo el nombre exacto de un
     * proyecto que ya tiene cotización (mismo Subcliente), se bloquea con el
     * mensaje literal del ticket en vez de crear un proyecto "vacío" duplicado
     * de nombre — la vía correcta es ir a Cotizaciones/Pipeline y usar el
     * historial de versiones existente.
     */
    public function store(StoreSalesProjectRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{sub_client_id: int, name: string, tag?: string|null} $validated */
        $validated = $request->validated();

        $alreadyQuoted = SalesProject::where('sub_client_id', $validated['sub_client_id'])
            ->where('name', $validated['name'])
            ->whereHas('quotes')
            ->exists();
        if ($alreadyQuoted) {
            return response()->json([
                'message' => 'Ya existe una cotización para este proyecto. Ve a Cotizaciones o a la tarjeta en '
                    . 'Pipeline para ver sus versiones y crear una nueva a partir de ahí.',
            ], 422);
        }

        $project = SalesProject::create($validated);

        return response()->json($this->format($project), 201);
    }

    /** @return array<string, mixed> */
    private function format(SalesProject $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'tag' => $project->tag];
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
