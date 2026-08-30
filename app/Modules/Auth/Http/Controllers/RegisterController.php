<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Services\DepartmentService;
use App\Modules\Auth\Services\UserService;
use App\Shared\Auth\TenantResolver;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly UserService       $userService,
        private readonly DepartmentService $departmentService,
        private readonly TenantResolver    $tenantResolver,
    ) {}

    /** GET /api/auth/departments — public endpoint for register form */
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

    /** POST /api/auth/register — creates a pending account awaiting admin approval */
    public function register(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.failed')], 401);
        }

        $tenant->makeCurrent();

        try {
            $data = $request->validate([
                'first_name'    => ['required', 'string', 'max:100'],
                'last_name'     => ['required', 'string', 'max:100'],
                'email'         => ['required', 'email', 'max:255'],
                'department_id' => ['required', 'integer', 'min:1'],
            ]);

            if (User::where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['Este correo ya está registrado.'],
                ]);
            }

            $this->userService->register([
                'first_name'    => (string) $data['first_name'],
                'last_name'     => (string) $data['last_name'],
                'email'         => (string) $data['email'],
                'department_id' => (int) $data['department_id'],
            ]);

            return response()->json([
                'message' => 'Tu solicitud fue enviada. Un administrador revisará tu cuenta.',
            ], 201);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
