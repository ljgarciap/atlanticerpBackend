<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Middleware;

use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Support\CotizaYaContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-1120 — envuelve las rutas `/ventas-diseno/cotizaya/*`: activa `CotizaYaContext` (para que los controladores de
 * partidas/ítems/contactos, que hacen `Quote::find($id)`, encuentren la cotización de venta chica) y lo apaga SIEMPRE
 * al terminar. Además, si la ruta trae el id de una cotización (`id` o `quoteId`), exige que sea de origen `cotizaya`:
 * sin esto, estas rutas servirían como puerta lateral para leer o editar una cotización comercial.
 */
final class EnsureCotizaYaContext
{
    public function handle(Request $request, Closure $next): Response
    {
        CotizaYaContext::enter();

        try {
            $routeId = $request->route('quoteId') ?? $request->route('id');
            if ($routeId !== null) {
                $isCotizaYa = is_numeric($routeId)
                    && Quote::query()->whereKey((int) $routeId)->where('origin', Quote::ORIGIN_COTIZAYA)->exists();

                if (! $isCotizaYa) {
                    return response()->json(['message' => 'Not found'], 404);
                }
            }

            return $next($request);
        } finally {
            CotizaYaContext::leave();
        }
    }
}
