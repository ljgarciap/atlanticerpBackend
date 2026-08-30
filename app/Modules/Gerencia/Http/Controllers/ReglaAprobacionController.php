<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Http\Controllers;

use App\Models\User;
use App\Modules\Gerencia\Http\Requests\ReglaAprobacionRequest;
use App\Modules\Gerencia\Models\ReglaAprobacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-163 (REQ-101) — CRUD de REGLA_APROBACION (tipo de solicitud → aprobador(es)).
 * Exclusivo de superadmin por ahora (ver docblock de routes/gerencia.php) — "quién más puede
 * configurar esto" queda pendiente de que el cliente lo defina.
 */
final class ReglaAprobacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $reglas = ReglaAprobacion::with('aprobadores')->orderBy('tipo')->get();

        return response()->json(['data' => $reglas->map(fn (ReglaAprobacion $r) => $this->format($r))->values()]);
    }

    public function store(ReglaAprobacionRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{tipo: string, activo: bool, observaciones?: string|null, aprobador_ids: list<int>} $validated */
        $validated = $request->validated();

        $regla = ReglaAprobacion::create([
            'tipo'          => $validated['tipo'],
            'activo'        => $validated['activo'],
            'observaciones' => $validated['observaciones'] ?? null,
        ]);
        $regla->aprobadores()->sync($validated['aprobador_ids']);

        return response()->json($this->format($regla->load('aprobadores')), 201);
    }

    public function update(ReglaAprobacionRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $regla = ReglaAprobacion::find($id);
        if ($regla === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{tipo: string, activo: bool, observaciones?: string|null, aprobador_ids: list<int>} $validated */
        $validated = $request->validated();

        $regla->update([
            'tipo'          => $validated['tipo'],
            'activo'        => $validated['activo'],
            'observaciones' => $validated['observaciones'] ?? null,
        ]);
        $regla->aprobadores()->sync($validated['aprobador_ids']);

        return response()->json($this->format($regla->load('aprobadores')));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $regla = ReglaAprobacion::find($id);
        if ($regla === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $regla->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(ReglaAprobacion $regla): array
    {
        return [
            'id'            => $regla->id,
            'tipo'          => $regla->tipo,
            'activo'        => $regla->activo,
            'observaciones' => $regla->observaciones,
            'aprobadores'   => $regla->aprobadores->map(fn (User $u) => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
            ])->values(),
            'created_at' => $regla->created_at?->toIso8601String(),
            'updated_at' => $regla->updated_at?->toIso8601String(),
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
