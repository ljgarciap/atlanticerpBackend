<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * `index()` es de acceso general (GET /api/modules, para poblar selects de la matriz de
 * roles/niveles) — el resto (`store`/`update`/`destroy`) vive bajo `permission:superadmin.all`
 * en routes/admin.php, mismo patrón que RoleController.
 */
final class ModuleController extends Controller
{
    public function __construct(private readonly ModuleService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            $this->service->listAll()->map(fn (Module $m) => $this->format($m))->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{key: string, name: string, sort_order?: int} $data */
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('modules', 'key')],
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $module = $this->service->create($data);

        return response()->json($this->format($module), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $module = $this->service->findOrFail($id);

        /** @var array{name?: string, sort_order?: int, is_active?: bool} $data */
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $module = $this->service->update($module, $data);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($module));
    }

    public function destroy(int $id): JsonResponse
    {
        $module = $this->service->findOrFail($id);

        try {
            $this->service->delete($module);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(Module $module): array
    {
        return [
            'id' => $module->id,
            'key' => $module->key,
            'name' => $module->name,
            'is_system' => $module->is_system,
            'is_active' => $module->is_active,
            'sort_order' => $module->sort_order,
        ];
    }
}
