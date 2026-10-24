<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\PasswordResetService;
use App\Modules\Auth\Support\AuthThrottle;
use App\Shared\Auth\TenantResolver;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

final class ForgotPasswordController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $service,
        private readonly TenantResolver       $tenantResolver,
    ) {}

    /** POST /api/auth/forgot-password */
    public function sendLink(Request $request): JsonResponse
    {
        $data   = $request->validate(['email' => ['required', 'email', 'max:255']]);

        // H4: se cuentan TODAS las solicitudes (existan o no los correos) por IP y por correo normalizado, así el 429 no revela si el
        // correo está registrado. Sin bloqueo permanente: el cupo vuelve solo.
        $cfg      = (array) config('throttle.forgot_password');
        $ipKey    = 'forgot:ip:' . $request->ip();
        $emailKey = 'forgot:email:' . AuthThrottle::emailKey($data['email']);
        if (($retry = AuthThrottle::reservar([
            $ipKey    => [(int) $cfg['ip_max'], (int) $cfg['ip_decay']],
            $emailKey => [(int) $cfg['email_max'], (int) $cfg['email_decay']],
        ], AuthThrottle::ABRIR)) !== null) {
            return AuthThrottle::respuesta($retry);
        }

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant !== null) {
            $tenant->makeCurrent();
            try {
                $this->service->sendResetLink($data['email']);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        // Always return same message — never reveal whether email exists
        return response()->json([
            'message' => 'Si el correo está registrado, recibirás un enlace en los próximos minutos.',
        ]);
    }

    /** POST /api/auth/reset-password */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => [
                'required', 'string', 'confirmed', 'min:8',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
            'password_confirmation' => ['required', 'string'],
        ]);

        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            throw ValidationException::withMessages([
                'token' => ['El enlace es inválido o ha expirado.'],
            ]);
        }

        $tenant->makeCurrent();

        try {
            $this->service->reset($data['token'], $data['password']);
        } finally {
            Tenant::forgetCurrent();
        }

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
