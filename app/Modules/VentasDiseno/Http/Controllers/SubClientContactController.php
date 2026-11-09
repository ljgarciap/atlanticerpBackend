<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreSubClientContactRequest;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Fase 31a — reemplaza un contacto por otro en una sola operación (útil con el contacto único, que no se puede
     * borrar): crea el nuevo, traslada a él las cotizaciones vinculadas al anterior y borra el anterior.
     */
    public function replace(StoreSubClientContactRequest $request, int $subClientId, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClient = SubClient::find($subClientId);
        $old       = $subClient?->contacts()->find($contactId);
        if ($subClient === null || $old === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, role: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();

        $new = DB::transaction(function () use ($subClient, $old, $validated): SubClientContact {
            $new = $subClient->contacts()->create($validated);

            $tenant = \App\Shared\Multitenancy\Tenant::current();
            $pivot  = $tenant !== null ? "{$tenant->slug}_ventas_diseno.quote_contacts" : 'quote_contacts';
            DB::table($pivot)->where('sub_client_contact_id', $old->id)->update(['sub_client_contact_id' => $new->id]);

            $old->delete();

            return $new;
        });

        return response()->json($this->format($new), 201);
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

        // Fase 23 (#2) — el alta de un subcliente exige al menos un contacto: el último no se borra (se edita o se reemplaza).
        if ($subClient->contacts()->count() <= 1) {
            return response()->json(['message' => 'Un cliente debe conservar al menos un contacto: agrega otro antes de borrar este.', 'code' => 'last_contact'], 422);
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
