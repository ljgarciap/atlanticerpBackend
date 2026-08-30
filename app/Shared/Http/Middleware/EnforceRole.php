<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based authorization: requires AuthenticateJwt to have run first
 * (reads `$request->attributes->get('jwt')`) and checks that the active
 * `role` claim is in the list of allowed roles passed as route parameters:
 *   Route::middleware('role:superadmin,management')->group(...)
 */
class EnforceRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $payload = $request->attributes->get('jwt');

        if (! is_array($payload) || ! isset($payload['role']) || ! is_string($payload['role'])) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! in_array($payload['role'], $allowedRoles, true)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return $next($request);
    }
}
