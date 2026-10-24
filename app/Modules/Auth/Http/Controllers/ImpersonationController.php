<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\ImpersonationException;
use App\Modules\Auth\Services\ImpersonationService;
use App\Modules\Auth\Services\OperatorReauth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-1158 — «Ver como usuario» (ADR-010). Dos endpoints:
 *  - `POST /api/users/{id}/impersonate` (superadmin): motivo + contraseña del propio superadmin → token de SOLO LECTURA con la identidad del
 *    objetivo. Sin `refresh_token`. Orden fijo (diseño §3.4): sin encadenar → actor revalidado en BD → motivo → contraseña → objetivo válido.
 *  - `POST /api/auth/impersonation/stop` (con el token de impersonación): termina la sesión. Es la única escritura que el middleware de solo
 *    lectura deja pasar.
 */
class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ImpersonationService $service,
        private readonly OperatorReauth $reauth,
    ) {}

    public function start(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $actor = $this->service->authorizeStart($request, $id, $data['reason']);

            // Re-autenticación con la contraseña del PROPIO superadmin (`operator_password`), con cupo de intentos propio de este flujo (C3).
            if (($denied = $this->reauth->verify($request, $actor->id, OperatorReauth::SCOPE_IMPERSONATION)) !== null) {
                // Solo la contraseña INCORRECTA es un fallo de seguridad que auditar; su ausencia es un error de formulario.
                if (($denied->getData(true)['code'] ?? null) === 'operator_reauth_failed') {
                    $this->service->auditReauthFailed($request, $actor, $id);
                }

                return $denied;
            }

            return response()->json($this->service->start($request, $actor, $id, $data['reason']), 201);
        } catch (ImpersonationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    public function stop(Request $request): JsonResponse
    {
        try {
            $this->service->stop($request);
        } catch (ImpersonationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(null, 204);
    }
}
