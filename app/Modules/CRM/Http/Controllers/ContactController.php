<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Services\ContactService;
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
            'type' => ['sometimes', 'string', Rule::in(Contact::ALLOWED_TYPES)],
            'q'    => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $result = $this->service->list(
            $user,
            $this->activeRole($request),
            (string) ($validated['type'] ?? ''),
            (string) ($validated['q']   ?? ''),
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
            'nombre'   => ['required', 'string', 'max:200'],
            'tipo'     => ['required', 'string', Rule::in(Contact::ALLOWED_TYPES)],
            'email'    => ['sometimes', 'nullable', 'email', 'max:200'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'empresa'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'notas'    => ['sometimes', 'nullable', 'string'],
        ]);

        $contact = $this->service->create($validated);

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

        $validated = $request->validate([
            'nombre'   => ['sometimes', 'string', 'max:200'],
            'tipo'     => ['sometimes', 'string', Rule::in(Contact::ALLOWED_TYPES)],
            'email'    => ['sometimes', 'nullable', 'email', 'max:200'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'empresa'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'notas'    => ['sometimes', 'nullable', 'string'],
        ]);

        $updated = $this->service->update($contact, $validated);

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

        $activeCount = $this->service->activeProjectCount($contact);
        if ($activeCount > 0) {
            return response()->json([
                'message'        => 'Contact has active projects',
                'active_projects' => $activeCount,
            ], 409);
        }

        $this->service->delete($contact);

        return response()->json(null, 204);
    }

    /** POST /api/crm/contacts/import */
    public function import(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $activeRole   = $this->activeRole($request);
        $isManagement = in_array($activeRole, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true);

        if (! $isManagement) {
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

    private function activeRole(Request $request): string
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return isset($payload['role']) ? $payload['role'] : '';
    }
}
