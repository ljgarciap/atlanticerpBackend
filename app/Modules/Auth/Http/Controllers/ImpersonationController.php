<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\ImpersonationException;
use App\Modules\Auth\Services\ImpersonationService;
use App\Modules\Auth\Services\OperatorReauth;
use App\Shared\Catalogs\CatalogService;
use Illuminate\Validation\ValidationException;
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
            'reason'     => ['nullable', 'string', 'max:500'],
            'reason_key' => ['nullable', 'string', 'max:60'],
        ]);

        // Fase 27b (b2): el motivo es una clave del catálogo `impersonation_reason` (+ texto, obligatorio si la entrada lo pide, p. ej. «Otro»).
        // Sin clave se conserva el comportamiento anterior: texto libre con el largo mínimo configurado.
        $key = isset($data['reason_key']) && $data['reason_key'] !== '' ? $data['reason_key'] : null;
        $text = trim((string) ($data['reason'] ?? ''));
        $fromCatalog = false;
        if ($key !== null) {
            $entry = CatalogService::entries('impersonation_reason')->firstWhere('key', $key);
            if ($entry === null) {
                throw ValidationException::withMessages(['reason_key' => [trans('auth.impersonation_reason_unknown')]]);
            }
            if ((bool) CatalogService::prop('impersonation_reason', $key, 'requiere_texto')) {
                if ($text === '') {
                    throw ValidationException::withMessages(['reason' => [trans('auth.impersonation_reason_text_required')]]);
                }
            } else {
                $fromCatalog = true;
                $text = $text !== '' ? $text : CatalogService::labelOf($entry);
            }
        } elseif ($text === '') {
            throw ValidationException::withMessages(['reason' => [trans('auth.impersonation_reason_text_required')]]);
        }

        try {
            $actor = $this->service->authorizeStart($request, $id, $text, skipMinLength: $fromCatalog);

            // Re-autenticación con la contraseña del PROPIO superadmin (`operator_password`), con cupo de intentos propio de este flujo (C3).
            if (($denied = $this->reauth->verify($request, $actor->id, OperatorReauth::SCOPE_IMPERSONATION)) !== null) {
                // Solo la contraseña INCORRECTA es un fallo de seguridad que auditar; su ausencia es un error de formulario.
                if (($denied->getData(true)['code'] ?? null) === 'operator_reauth_failed') {
                    $this->service->auditReauthFailed($request, $actor, $id);
                }

                return $denied;
            }

            return response()->json($this->service->start($request, $actor, $id, $text, $key), 201);
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
