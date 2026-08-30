<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Middleware;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN1 de REQ-555 (SCRUM-632): Configuración Fiscal es exclusiva de Mark — "ningún otro rol, ni
 * siquiera Felix o Gerencia en general, tiene acceso a verla ni editarla". A diferencia del gate
 * de aprobación de Compras (PurchaseOrderController::approve()), acá aplica a TODA la pantalla,
 * lectura incluida — por eso es un middleware sobre el grupo completo de rutas, no un chequeo
 * puntual en una sola acción. No usa el sistema de permisos por nivel/rol: es una excepción de
 * identidad (mismo criterio que `mark_approver_user_id` en ComprasSettings), fail-closed si el
 * approver todavía no está configurado.
 *
 * `superadmin.all` bypassa este gate (decisión de Luis, 2026-08-22) — mismo criterio que
 * `CheckPermission`: la exclusividad de Mark es sobre el resto de los roles de negocio, nunca
 * sobre el nivel técnico de soporte/administración de la plataforma.
 */
class EnsureIsMarkApprover
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

        $markApproverId = AdminContFiscalSettings::current()->mark_approver_user_id;

        if ($markApproverId === null || (int) $payload['sub'] !== $markApproverId) {
            return response()->json(['message' => 'Esta pantalla es exclusiva de Mark.'], 403);
        }

        return $next($request);
    }
}
