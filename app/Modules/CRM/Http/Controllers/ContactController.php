<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Services\ContactService;
use App\Shared\Catalogs\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class ContactController extends Controller
{
    public function __construct(private readonly ContactService $service) {}

    /** GET /api/crm/contacts?type=&q= */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(CatalogService::keys('contact_role'))],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $result = $this->service->list(
            $user,
            $user->canViewTeamV2('crm'),
            (string) ($validated['type'] ?? ''),
            (string) ($validated['q'] ?? ''),
        );

        return response()->json($result);
    }

    /** POST /api/crm/contacts */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'tipo' => ['required', 'string', Rule::in(CatalogService::keys('contact_role'))],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'empresa' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notas' => ['sometimes', 'nullable', 'string'],
        ]);

        $contact = $this->service->create($validated, $user);

        return response()->json($contact, 201);
    }

    /** PUT /api/crm/contacts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $contact = Contact::find($id);
        if ($contact === null) {
            return response()->json(['message' => 'Contact not found'], 404);
        }

        if (! $this->canManage($contact, $user)) {
            return response()->json(['message' => 'Solo quien creó el contacto o quien ve al equipo puede editarlo.'], 403);
        }

        $validated = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:200'],
            'tipo' => ['sometimes', 'string', Rule::in(CatalogService::keys('contact_role'))],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'empresa' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notas' => ['sometimes', 'nullable', 'string'],
        ]);

        $updated = $this->service->update($contact, $validated, $user);

        return response()->json($updated);
    }

    /** DELETE /api/crm/contacts/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $contact = Contact::find($id);
        if ($contact === null) {
            return response()->json(['message' => 'Contact not found'], 404);
        }

        if (! $this->canManage($contact, $user)) {
            return response()->json(['message' => 'Solo quien creó el contacto o quien ve al equipo puede borrarlo.'], 403);
        }

        $activeCount = $this->service->activeProjectCount($contact);
        if ($activeCount > 0) {
            return response()->json([
                'message' => 'Contact has active projects',
                'active_projects' => $activeCount,
            ], 409);
        }

        $this->service->delete($contact);

        return response()->json(null, 204);
    }

    /** Fase 22 (B4): un contacto con dueño lo gestiona su creador o quien ve al equipo; uno sin dueño (anterior a la fase) lo gestiona cualquiera del CRM. */
    private function canManage(Contact $contact, User $user): bool
    {
        return $contact->created_by === null || $contact->created_by === $user->id || $user->canViewTeamV2('crm');
    }

    /** POST /api/crm/contacts/import */
    public function import(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $user->canViewTeamV2('crm')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->service->import();

        return response()->json($result);
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
