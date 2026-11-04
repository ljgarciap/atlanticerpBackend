<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Middleware;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 23 (#8) — pedir `scope=team` sin poder ver al equipo ya no se degrada en silencio a «lo propio»: responde 403.
 * La pantalla solo ofrece el selector a quien ve al equipo; esto cierra la puerta a quien llama la API a mano y evita que
 * una cifra de «mi alcance» se lea como la del equipo.
 *
 * Fase 25: la regla depende de la pantalla — `team.scope` (Ventas & Diseño), `team.scope:bodega` (resumen de Bodega) y
 * `team.scope:pipeline` (Ventas & Diseño + los permisos propios del Pipeline: `pipeline.read_all`, `pipeline.edit`).
 * No se aplica a Pedidos (su default es `team` y la ruta ya filtra por permiso) ni al calendario (regla por módulo).
 */
final class EnsureTeamScopeAllowed
{
    public function handle(Request $request, Closure $next, string $regla = 'ventas_diseno'): Response
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

        if (! $this->puedeVerEquipo($user, $regla)) {
            return response()->json([
                'message' => 'No tienes permiso para ver el alcance de equipo.',
                'code' => 'team_scope_forbidden',
            ], 403);
        }

        return $next($request);
    }

    private function puedeVerEquipo(User $user, string $regla): bool
    {
        return match ($regla) {
            'bodega' => $user->canViewTeamV2('bodega'),
            'pipeline' => PipelineCard::userCanSeeTeam($user),
            default => $user->canViewTeamV2('ventas_diseno'),
        };
    }
}
