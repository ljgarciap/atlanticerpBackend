<?php

declare(strict_types=1);

namespace App\Modules\Lumo\Http\Controllers;

use App\Models\User;
use App\Modules\Lumo\Services\LumoIdentityBridgeService;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-925. La ruta ya exige `permission:lumo.view` (defensa en profundidad, ver routes/lumo.php)
 * — este controller no vuelve a chequear el permiso, solo emite el código.
 */
final class LumoWidgetController extends Controller
{
    public function __construct(private readonly LumoIdentityBridgeService $service) {}

    public function issueCode(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            return response()->json($this->service->issueCode($user));
        } catch (CircuitOpenException|\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'LUMO no disponible en este momento.'], 503);
        }
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
