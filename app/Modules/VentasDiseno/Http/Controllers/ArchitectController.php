<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreArchitectRequest;
use App\Modules\VentasDiseno\Http\Requests\UpdateArchitectRequest;
use App\Modules\VentasDiseno\Models\Architect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-030 / SCRUM-122: buscar/crear un Arquitecto acotado a un Subcliente ya
 * elegido (mismo patrón que SalesProjectController) — ya no es un directorio
 * global.
 */
final class ArchitectController extends Controller
{
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

        $query = Architect::query()->where('sub_client_id', (int) $subClientId)->orderBy('name');

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $architects = $query->limit(20)->get();

        return response()->json([
            'data' => $architects->map(fn (Architect $a) => $this->format($a))->values(),
        ]);
    }

    public function store(StoreArchitectRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{sub_client_id: int, name: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();

        $architect = Architect::create($validated);

        return response()->json($this->format($architect), 201);
    }

    /**
     * SCRUM-734 (RN8.5) — edición in-place: si se cambia el teléfono/correo de un
     * Arquitecto ya guardado, actualiza su registro real (asociado al Subcliente),
     * no una copia aislada de esa cotización — mismo comportamiento que ya tiene
     * SubClientContactController::update().
     */
    public function update(UpdateArchitectRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $architect = Architect::find($id);
        if ($architect === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();
        $architect->update($validated);

        return response()->json($this->format($architect));
    }

    /** @return array<string, mixed> */
    private function format(Architect $architect): array
    {
        return [
            'id' => $architect->id, 'name' => $architect->name,
            'phone' => $architect->phone, 'email' => $architect->email,
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
