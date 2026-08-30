<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-based authorization: requires AuthenticateJwt to have run first
 * (reads `$request->attributes->get('jwt')`) and checks that the `permissions`
 * array in the JWT payload contains the required permission:
 *   Route::middleware('permission:crm.write')->...
 *
 * Multiple comma-separated permissions are OR'd — Laravel splits middleware
 * parameters on commas, so `permission:compras.read,bodega.read` grants access
 * to an actor with EITHER (used for read-only cross-module routes, e.g. a
 * Purchase Order detail page also surfaced from a Bodega Home panel).
 *
 * Actors with `superadmin.all` bypass all permission checks.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        if (! is_array($payload)) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        if (in_array('superadmin.all', $permissions, true)) {
            return $next($request);
        }

        foreach ($required as $permission) {
            if (in_array($permission, $permissions, true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => trans('auth.unauthorized')], 403);
    }
}
