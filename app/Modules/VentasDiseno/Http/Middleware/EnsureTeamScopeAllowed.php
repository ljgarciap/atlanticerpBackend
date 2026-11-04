<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 23 (#8) — pedir `scope=team` sin poder ver al equipo ya no se degrada en silencio a «lo propio»: responde 403.
 * La pantalla solo ofrece el selector a quien ve al equipo; esto cierra la puerta a quien llama la API a mano y evita que
 * una cifra de «mi alcance» se lea como la del equipo. No se aplica al Pipeline ni a Pedidos: ahí el alcance de equipo
 * también lo dan permisos propios (`pipeline.read_all`, `pipeline.edit`) y el default de Pedidos es `team`.
 */
final class EnsureTeamScopeAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('scope') !== 'team') {
            return $next($request);
        }

        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');
        $user = isset($payload['sub']) ? User::find((int) $payload['sub']) : null;

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $user->canViewTeamV2('ventas_diseno')) {
            return response()->json([
                'message' => 'No tienes permiso para ver el alcance de equipo.',
                'code' => 'team_scope_forbidden',
            ], 403);
        }

        return $next($request);
    }
}
