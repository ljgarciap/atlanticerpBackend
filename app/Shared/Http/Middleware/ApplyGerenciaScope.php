<?php

namespace App\Shared\Http\Middleware;

use App\Modules\Auth\Support\RoleKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-1150 — alcance de Gerencia dentro de CRM y Ventas & Diseño concedido a una PERSONA (no a un rol).
 *
 * Quien tenga el permiso `ventas_diseno.gerencia_scope` en el JWT se trata como `management` SOLO en las rutas
 * de CRM y Ventas & Diseño (se aplica en `bootstrap/app.php` a esos dos archivos de rutas): los grupos `role:superadmin,management`,
 * el alcance «ve todos los proyectos» de los controladores de CRM y los gates de Dashboard/Pedidos (`platform.management`, `ventas_diseno.orders.access`) lo reconocen sin cambiar cada sitio.
 * El rol real sigue en `real_role`, y fuera de esas rutas (Admin&Cont, Bodega, Servicios…) el usuario conserva su rol de siempre.
 *
 * Debe correr DESPUÉS de AuthenticateJwt. El permiso solo lo concede un superadmin (`extra_permissions`).
 */
class ApplyGerenciaScope
{
    public const PERMISSION = 'ventas_diseno.gerencia_scope';

    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->attributes->get('jwt');

        if (is_array($payload)
            && is_array($payload['permissions'] ?? null)
            && in_array(self::PERMISSION, $payload['permissions'], true)
            && ($payload['role'] ?? null) !== RoleKeys::SUPERADMIN) {
            $payload['real_role'] = $payload['role'] ?? null;
            $payload['role'] = RoleKeys::MANAGEMENT;
            // Las rutas de CRM/Ventas & Diseño ya no deciden por nombre de rol sino por permiso (Fase 1, bloque D): quien tiene el alcance de Gerencia
            // recibe, solo en estas rutas y solo para esta petición, los permisos de rol completo que antes daba el rol `management`.
            $payload['permissions'] = array_values(array_unique([...$payload['permissions'], 'platform.management', 'ventas_diseno.orders.access']));
            $request->attributes->set('jwt', $payload);
        }

        return $next($request);
    }
}
