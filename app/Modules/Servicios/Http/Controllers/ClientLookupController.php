<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Services\ClientLookupService;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** REQ-246 — buscador de solo lectura Cliente Master → Subcliente → Proyecto para Nuevo ticket. */
final class ClientLookupController extends Controller
{
    public function __construct(private readonly ClientLookupService $lookup) {}

    public function masterClients(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        return response()->json(['data' => $this->lookup->searchMasterClients($search)]);
    }

    public function subClients(Request $request, int $masterClientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        return response()->json(['data' => $this->lookup->searchSubClients($masterClientId, $search)]);
    }

    public function projects(Request $request, int $subClientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (SubClient::find($subClientId) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->lookup->projectsForSubClient($subClientId)]);
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
