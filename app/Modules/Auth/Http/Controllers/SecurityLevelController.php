<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\PermissionCatalog;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SecurityLevelModulePermission;
use App\Modules\Auth\Services\SecurityLevelService;
use App\Modules\Auth\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class SecurityLevelController extends Controller
{
    public function __construct(private readonly SecurityLevelService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            return response()->json(
                $this->service->listAll()->map(fn (SecurityLevel $l) => $this->format($l))->values()
            );
        }

        $search    = (string) ($request->query('search', ''));
        $perPage   = min((int) ($request->query('per_page', 20)), 100);
        $paginator = $this->service->list($search, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (SecurityLevel $l) => $this->format($l))->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Returns active permissions grouped by module, ordered by sort_order.
     *
     * GET /api/permission-catalog
     * Access: role:superadmin,management
     *
     * @return JsonResponse
     */
    public function permissionCatalog(): JsonResponse
    {
        /** @var array<string, list<array{key: string, label: string, description: string|null}>> $grouped */
        $grouped = [];

        PermissionCatalog::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (PermissionCatalog $item) use (&$grouped): void {
                $grouped[$item->module][] = [
                    'key'         => $item->key,
                    'label'       => $item->label,
                    'description' => $item->description,
                ];
            });

        return response()->json(['catalog' => $grouped]);
    }

    /**
     * ADR-006, Decisión 9 — permission:security.levels (administración de niveles, sin cambios
     * de alcance respecto a lo que ya gobernaba este permiso).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, level?: int, description?: string|null, permissions?: list<string>} $data */
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100', $this->uniqueName()],
            'level'         => ['sometimes', 'integer', 'min:1', 'max:32767', $this->freeLevel()],
            'description'   => ['sometimes', 'nullable', 'string'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::activeKeys())],
        ]);

        $level = $this->service->create($data);

        return response()->json($this->format($level), 201);
    }

    /** Fase 27b (b1): el nombre de un nivel es único (sin distinguir mayúsculas), salvo el propio nivel al editarlo. */
    private function uniqueName(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            $taken = SecurityLevel::whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($taken) {
                $fail('Ya existe un nivel de seguridad con ese nombre.');
            }
        };
    }

    /** Fase 27b (b1): si se pide un número de nivel, debe estar libre (antes se ignoraba en silencio). */
    private function freeLevel(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (SecurityLevel::where('level', (int) $value)->exists()) {
                $fail('Ese número de nivel ya está ocupado.');
            }
        };
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var array{name?: string, description?: string|null, permissions?: list<string>} $data */
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100', $this->uniqueName($id)],
            'description'   => ['sometimes', 'nullable', 'string'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::activeKeys())],
        ]);

        $level = $this->service->findOrFail($id);
        $level = $this->service->update($level, $data);

        return response()->json($this->format($level));
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $level  = $this->service->findOrFail($id);
        $active = (bool) $request->input('is_active', !$level->is_active);
        $level  = $this->service->setActive($level, $active);
        return response()->json($this->format($level));
    }

    /**
     * ADR-006, Decisión 10 — GET /api/security-levels/{id}/permissions.
     */
    public function permissions(int $id): JsonResponse
    {
        $level = $this->service->findOrFail($id);

        return response()->json(
            $this->service->getModulePermissions($level)->map(fn (SecurityLevelModulePermission $p) => $this->formatPermission($p))->values()
        );
    }

    /**
     * ADR-006, Decisión 10 — PUT /api/security-levels/{id}/permissions — permission:security.levels,
     * sin cambios (administración de niveles, no del catálogo de roles).
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $level = $this->service->findOrFail($id);

        /** @var array{rows: list<array{module: string, can_edit: bool, can_approve: bool}>} $data */
        $data = $request->validate([
            'rows'                => ['required', 'array'],
            'rows.*.module'       => ['required', 'string', Rule::in(ModuleCatalog::keys())],
            'rows.*.can_edit'     => ['required', 'boolean'],
            'rows.*.can_approve'  => ['required', 'boolean'],
        ]);

        $rows = $this->service->upsertModulePermissions($level, $data['rows']);

        return response()->json($rows->map(fn (SecurityLevelModulePermission $p) => $this->formatPermission($p))->values());
    }

    /** @return array<string, mixed> */
    private function formatPermission(SecurityLevelModulePermission $permission): array
    {
        return [
            'module'      => $permission->module,
            'can_edit'    => $permission->can_edit,
            'can_approve' => $permission->can_approve,
        ];
    }

    /** @return array<string, mixed> */
    private function format(SecurityLevel $level): array
    {
        return [
            'id'          => $level->id,
            'level'       => $level->level,
            'name'        => $level->name,
            'description' => $level->description,
            'permissions' => $level->permissions,
            'is_active'   => $level->is_active,
        ];
    }
}
