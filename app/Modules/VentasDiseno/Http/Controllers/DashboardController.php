<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Dashboard CRM (SCRUM-684→689, Batch C) — acceso restringido a role:superadmin,management
 * en la ruta (REQ-609), sin scope Mío/Equipo: siempre lee el equipo completo.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->service->summary());
    }

    public function remind(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $result = $this->service->remind($user->id);

        if ($result['notified'] === [] && $result['already_sent_today'] === []) {
            return response()->json(['message' => 'No hay propuestas vencidas para recordar.'], 422);
        }

        // Hallazgo de Pre-QA (2026-07-31): distinguir "nada que recordar" de "ya se recordó
        // hoy" — el 422 genérico de arriba era engañoso si en realidad SÍ había propuestas
        // vencidas pero el botón ya se había presionado antes en el día (ver DashboardService).
        if ($result['notified'] === []) {
            return response()->json([
                'message'            => 'Ya se envió un recordatorio hoy a cada responsable de las propuestas vencidas.',
                'notified'           => [],
                'already_sent_today' => $result['already_sent_today'],
            ], 200);
        }

        return response()->json($result);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
