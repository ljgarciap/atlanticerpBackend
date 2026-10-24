<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Services\DepartmentService;
use App\Shared\Auth\TenantResolver;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departmentService,
        private readonly TenantResolver    $tenantResolver,
    ) {}

    /**
     * GET /api/auth/departments — público. SCRUM-1177: el registro público se cerró; lo sigue usando el perfil (EditProfileDrawer).
     */
    public function departments(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['data' => []]);
        }

        $tenant->makeCurrent();

        try {
            $depts = $this->departmentService->listAll();

            return response()->json([
                'data' => $depts->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            ]);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
