<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreSubClientContactRequest;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-066: gestión de contactos por Subcliente. A diferencia de PipelineCardContact
 * (contactos propios de una tarjeta), un Subcliente no tiene owner_id ni
 * restricciones por etapa — es un directorio compartido, gateado solo por el
 * permiso de módulo (ventas_diseno.edit) vía middleware de ruta.
 */
final class SubClientContactController extends Controller
{
    // REQ-031: buscador de contactos del Subcliente al vincularlos a una cotización.
    public function index(Request $request, int $subClientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = SubClientContact::query()->where('sub_client_id', $subClientId)->orderBy('name');

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $contacts = $query->limit(20)->get();

        return response()->json(['data' => $contacts->map(fn (SubClientContact $c) => $this->format($c))->values()]);
    }

    public function store(StoreSubClientContactRequest $request, int $subClientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, role: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();

        $contact = $subClient->contacts()->create($validated);

        return response()->json($this->format($contact), 201);
    }

    public function update(StoreSubClientContactRequest $request, int $subClientId, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact = $subClient->contacts()->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, role: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();
        $contact->update($validated);

        return response()->json($this->format($contact));
    }

    public function destroy(Request $request, int $subClientId, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact = $subClient->contacts()->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(SubClientContact $contact): array
    {
        return [
            'id'    => $contact->id,
            'name'  => $contact->name,
            'role'  => $contact->role,
            'phone' => $contact->phone,
            'email' => $contact->email,
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
