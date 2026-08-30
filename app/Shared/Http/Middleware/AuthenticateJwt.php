<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida el JWT y, a partir del claim `tenant`, inicializa el search_path
 * de Postgres antes de pasar al handler. El `forgetCurrent()` se ejecuta
 * siempre en el finally, incluso si el handler lanza una excepción.
 */
class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $token = substr($header, 7);
        $payload = $this->jwt->decode($token);

        if ($payload === null) {
            return response()->json(['message' => trans('auth.token_invalid')], 401);
        }

        $tenantSlug = isset($payload['tenant']) && is_string($payload['tenant'])
            ? $payload['tenant']
            : null;

        if ($tenantSlug === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $request->attributes->set('jwt', $payload);
        $tenant->makeCurrent();

        try {
            return $next($request);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
