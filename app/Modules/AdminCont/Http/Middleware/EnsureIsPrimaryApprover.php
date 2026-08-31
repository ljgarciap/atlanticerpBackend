<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Middleware;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuración Fiscal es exclusiva de un único aprobador configurable (`primary_approver_user_id`
 * en `AdminContFiscalSettings`, ajustable desde la pantalla de administración) — ningún otro rol,
 * ni siquiera Gerencia en general, tiene acceso a verla ni editarla por defecto. A diferencia del
 * gate de aprobación de Compras (PurchaseOrderController::approve()), acá aplica a TODA la
 * pantalla, lectura incluida — por eso es un middleware sobre el grupo completo de rutas, no un
 * chequeo puntual en una sola acción. No usa el sistema de permisos por nivel/rol: es una
 * excepción de identidad (mismo criterio que `primary_approver_user_id` en ComprasSettings),
 * fail-closed si el aprobador todavía no está configurado.
 *
 * `superadmin.all` bypassa este gate — mismo criterio que `CheckPermission`: la exclusividad del
 * aprobador configurado es sobre el resto de los roles de negocio, nunca sobre el nivel técnico
 * de soporte/administración de la plataforma.
 */
class EnsureIsPrimaryApprover
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        if (in_array('superadmin.all', $permissions, true)) {
            return $next($request);
        }

        $primaryApproverId = AdminContFiscalSettings::current()->primary_approver_user_id;

        if ($primaryApproverId === null || (int) $payload['sub'] !== $primaryApproverId) {
            return response()->json(['message' => 'Esta pantalla es exclusiva del aprobador configurado.'], 403);
        }

        return $next($request);
    }
}
