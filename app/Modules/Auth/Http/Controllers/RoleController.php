<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Auth\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * ADR-006, Decisión 10. `index()` es de acceso general (GET /api/roles, para poblar selects) — el
 * resto (`store`/`update`/`destroy`/`visibility`/`updateVisibility`) vive bajo
 * `permission:superadmin.all` en routes/admin.php.
 */
final class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->listAll()->map(fn (Role $r) => $this->format($r))->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{key: string, name: string, default_security_level_id?: int|null} $data */
        $data = $request->validate([
            'key'                       => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'key')],
            'name'                      => ['required', 'string', 'max:80'],
            'default_security_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('security_levels', 'id')],
        ]);

        $role = $this->service->create($data);

        return response()->json($this->format($role), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);

        /** @var array{name?: string, default_security_level_id?: int|null} $data */
        $data = $request->validate([
            'name'                      => ['sometimes', 'string', 'max:80'],
            'default_security_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('security_levels', 'id')],
        ]);

        try {
            $role = $this->service->update($role, $data);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($role));
    }

    public function destroy(int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);

        try {
            $this->service->delete($role);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(null, 204);
    }

    public function visibility(int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);

        return response()->json($this->service->getVisibility($role)->map(fn (RoleModuleVisibility $v) => $this->formatVisibility($v))->values());
    }

    public function updateVisibility(Request $request, int $id): JsonResponse
    {
        $role = $this->service->findOrFail($id);

        /** @var array{rows: list<array{module: string, can_view: int, can_view_team: bool}>} $data */
        $data = $request->validate([
            'rows'                 => ['required', 'array'],
            'rows.*.module'        => ['required', 'string', Rule::in(ModuleCatalog::keys())],
            'rows.*.can_view'      => ['required', 'integer', Rule::in([RoleModuleVisibility::VIEW_NONE, RoleModuleVisibility::VIEW_READONLY, RoleModuleVisibility::VIEW_FULL])],
            'rows.*.can_view_team' => ['required', 'boolean'],
        ]);

        $rows = $this->service->upsertVisibility($role, $data['rows']);

        return response()->json($rows->map(fn (RoleModuleVisibility $v) => $this->formatVisibility($v))->values());
    }

    /** @return array<string, mixed> */
    private function format(Role $role): array
    {
        return [
            'id'                        => $role->id,
            'key'                       => $role->key,
            'name'                      => $role->name,
            'is_superadmin'             => $role->is_superadmin,
            'is_system'                 => $role->is_system,
            'is_active'                 => $role->is_active,
            'default_security_level_id' => $role->default_security_level_id,
        ];
    }

    /** @return array<string, mixed> */
    private function formatVisibility(RoleModuleVisibility $visibility): array
    {
        return [
            'module'        => $visibility->module,
            'can_view'      => $visibility->can_view,
            'can_view_team' => $visibility->can_view_team,
        ];
    }
}
