<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\CreateDepartmentRequest;
use App\Modules\Auth\Http\Requests\UpdateDepartmentRequest;
use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('all')) {
            return response()->json(
                $this->service->listAll($request->filled('incluir') ? (int) $request->query('incluir') : null)->map(fn (Department $d) => $this->format($d))->values()
            );
        }

        $search    = (string) ($request->query('search', ''));
        $perPage   = min((int) ($request->query('per_page', 20)), 100);
        $paginator = $this->service->list($search, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Department $d) => $this->format($d))->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(CreateDepartmentRequest $request): JsonResponse
    {
        $dept = $this->service->create((string) $request->validated('name'));
        return response()->json($this->format($dept), 201);
    }

    public function update(UpdateDepartmentRequest $request, int $id): JsonResponse
    {
        $dept = $this->service->findOrFail($id);
        $dept = $this->service->update($dept, (string) $request->validated('name'));
        return response()->json($this->format($dept));
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $dept   = $this->service->findOrFail($id);
        $active = (bool) $request->input('is_active', !$dept->is_active);
        $dept   = $this->service->setActive($dept, $active);
        return response()->json($this->format($dept));
    }

    /** Fase 31b — reasigna los usuarios de un departamento a otro activo (o los deja sin departamento). */
    public function reassign(Request $request, int $id): JsonResponse
    {
        $dept = $this->service->findOrFail($id);
        $data = $request->validate([
            'to_department_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('departments', 'id')->where('is_active', true)],
        ]);
        $toId = isset($data['to_department_id']) ? (int) $data['to_department_id'] : null;
        if ($toId === $dept->id) {
            return response()->json(['message' => 'El destino debe ser otro departamento.'], 422);
        }
        $moved = $this->service->reassignUsers($dept, $toId);

        return response()->json(['movidos' => $moved, 'department' => $this->format($dept)]);
    }

    /** @return array<string, mixed> */
    private function format(Department $dept): array
    {
        return [
            'id'        => $dept->id,
            'name'      => $dept->name,
            'is_active' => $dept->is_active,
            'user_count' => $this->service->userCount($dept),
        ];
    }
}
