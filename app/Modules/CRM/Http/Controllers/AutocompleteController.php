<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class AutocompleteController extends Controller
{
    // Fields allowed for distinct-value autocomplete — explicit allowlist against arbitrary column queries.
    private const ALLOWED_FIELDS = ['encargado', 'developer', 'arquitecto', 'despacho', 'ubicacion', 'contacto', 'area'];

    /**
     * GET /api/crm/autocomplete/fields?field={field}&q={query}
     *
     * Returns up to 20 distinct non-null values for the given project field
     * that contain the query string (case-insensitive).
     */
    public function fields(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(self::ALLOWED_FIELDS)],
            'q'     => ['nullable', 'string', 'max:100'],
        ]);

        $field = $validated['field'];
        $query = $validated['q'] ?? '';

        $results = Project::query()
            ->whereNotNull($field)
            ->where($field, '!=', '')
            ->when($query !== '', fn ($q) => $q->where($field, 'ilike', "%{$query}%"))
            ->distinct()
            ->orderBy($field)
            ->limit(20)
            ->pluck($field);

        return response()->json(['data' => $results->values()]);
    }

    /**
     * GET /api/crm/autocomplete/contacts?q={query}
     *
     * Returns up to 20 contacts from the directory whose name matches the query.
     * Used to populate the contacto field and autofill email + telefono.
     */
    public function contacts(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');

        $contacts = Contact::query()
            ->when($query !== '', fn ($q) => $q->where('nombre', 'ilike', "%{$query}%"))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['nombre', 'email', 'telefono']);

        return response()->json([
            'data' => $contacts->map(fn (Contact $c) => [
                'nombre'   => $c->nombre,
                'email'    => $c->email,
                'telefono' => $c->telefono,
            ])->values(),
        ]);
    }

    /**
     * GET /api/crm/autocomplete/users?q={query}
     *
     * Returns up to 20 active users whose name or email matches the query.
     * Used to populate the assignee selector on project forms.
     */
    public function users(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');

        $users = User::where('is_active', true)
            ->when($query !== '', function ($q) use ($query): void {
                $q->where(function ($inner) use ($query): void {
                    $inner->where('first_name', 'ilike', "%{$query}%")
                          ->orWhere('last_name',  'ilike', "%{$query}%")
                          ->orWhere('email',       'ilike', "%{$query}%");
                });
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'email']);

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'email'      => $u->email,
            ])->values(),
        ]);
    }
}
