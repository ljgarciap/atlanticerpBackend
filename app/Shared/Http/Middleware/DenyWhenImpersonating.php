<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-1158 («Ver como usuario», condición C6 del threat model) — red de seguridad para una ruta GET que, pese al método, ESCRIBE algo (una fila, un
 * objeto en S3). El modo solo lectura se apoya en el método HTTP, así que lo correcto es que esa ruta sea POST; si por alguna razón no puede serlo
 * todavía, `->middleware('no_impersonation')` la niega por completo cuando el JWT lleva `act`. Inventario: `docs/security/INVENTARIO-GET-QUE-ESCRIBEN-SCRUM-1158.md`.
 *
 * SIN USO HOY: las dos únicas rutas que lo llevaban (`/bodega/returns/{id}/form` y `/delivery-guide`) pasaron a POST en SCRUM-1180. El alias y la
 * clase se conservan registrados para el próximo GET que aparezca con ese problema.
 */
class DenyWhenImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->attributes->get('jwt');

        if (is_array($payload) && array_key_exists('act', $payload)) {
            return BlockWritesWhenImpersonating::reject($request);
        }

        return $next($request);
    }
}
