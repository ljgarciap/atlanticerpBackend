<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a la documentación Swagger en entornos de producción.
 *
 * Se aplica únicamente en los middlewares de las rutas de l5-swagger
 * (api, asset, docs). En cualquier APP_ENV distinto de 'production' se
 * deja pasar la request sin modificaciones.
 */
class SwaggerProductionGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            abort(404);
        }

        return $next($request);
    }
}
