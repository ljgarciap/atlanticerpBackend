<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\JwtActor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-916 — setea `app.current_user_id` en la sesión de Postgres para que
 * `fn_audit_row_change()` (trigger de auditoría a nivel de DB) sepa qué usuario de la app hizo un
 * cambio, no solo que "atlanticerp_app" (el rol de conexión) lo hizo.
 *
 * DECISIÓN DE DISEÑO — SET SESSION + RESET en finally, NO una transacción envolvente por request
 * con SET LOCAL. Se consideró SET LOCAL dentro de una transacción abierta al inicio del request y
 * cerrada al final (evita por diseño que el valor sobreviva a la conexión), pero se descartó:
 * envolver TODO el request en una sola transacción mantendría abierto un lock/transacción de
 * Postgres durante I/O externo del controller (S3, Claude API, email, jobs) — riesgo real de
 * locks prolongados y de que un `DB::transaction()` YA existente en el código quede anidado como
 * savepoint, con la sorpresa de que un rollback del request completo deshaga un savepoint que el
 * código de negocio ya daba por confirmado.
 *
 * Es seguro usar SET SESSION (no SET LOCAL) porque AtlanticERP corre en PHP-FPM plano, sin Octane/Swoole
 * ni PgBouncer en modo transaction/session pooling delante de Postgres (verificado: sin
 * `PDO::ATTR_PERSISTENT`, sin Octane/Swoole en composer.json, conexión directa app→Postgres en
 * infra/docker-compose*.yml) — cada request de PHP-FPM abre su propia conexión nueva y la conexión
 * NUNCA se reutiliza para otro request, así que no hay riesgo de que un app_user_id quede pegado
 * de un request al siguiente. El `RESET` en el `finally` (mismo patrón que
 * `Tenant::forgetCurrent()` en AuthenticateJwt) es defensa en profundidad, no la protección real.
 *
 * ⚠️ Si el proyecto migra a Laravel Octane/Swoole o agrega PgBouncer con pooling de
 * sesión/transacción delante de Postgres, esta premisa deja de ser válida — en ese escenario hay
 * que rediseñar esto (ej. SET LOCAL dentro de una transacción por-escritura, no por-request).
 *
 * Corre DESPUÉS de AuthenticateJwt (bootstrap/app.php) — lee el autor del payload JWT ya validado
 * que AuthenticateJwt deja en $request->attributes (`sub`, o `act.sub` en una sesión «Ver como usuario»:
 * ver App\Shared\Auth\JwtActor).
 */
class AttributeDbChangesToUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $request->attributes->get('jwt');
        // SCRUM-1158 (C1) — en una sesión «Ver como usuario» el cambio de BD se atribuye al actor real (`act.sub`), no al suplantado (`sub`).
        $userId = JwtActor::id(is_array($jwt) ? $jwt : null);

        if ($userId === null) {
            return $next($request);
        }

        try {
            // Postgres no acepta parámetros bindeados en SET/SET SESSION (`SET x = $1` es un error
            // de sintaxis, no una consulta parametrizable normal) — bug real encontrado corriendo
            // los tests de este ticket. $userId ya está validado como entero puro arriba
            // (is_int/ctype_digit), así que interpolarlo directo es seguro (no es texto arbitrario
            // de un request, es un id ya validado).
            DB::statement("SET SESSION app.current_user_id = {$userId}");

            return $next($request);
        } finally {
            DB::statement("RESET app.current_user_id");
        }
    }
}
