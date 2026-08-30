<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\OrdersSettingsRequest;
use App\Modules\VentasDiseno\Models\OrdersSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-625: CRUD del umbral de margen bajó/mejoró de Pedidos — gateado por el mismo permiso
 * angosto que PricingSettingsController (`ventas_diseno.pricing.configure`), es la misma
 * clase de configuración (fórmula/umbral de margen), no amerita un permiso nuevo.
 */
final class OrdersSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->format(OrdersSettings::current()));
    }

    public function update(OrdersSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $settings = OrdersSettings::current();
        $settings->update($request->validated());

        /** @var OrdersSettings $fresh */
        $fresh = $settings->fresh();

        return response()->json($this->format($fresh));
    }

    /** @return array<string, mixed> */
    private function format(OrdersSettings $settings): array
    {
        return ['margin_drop_threshold_pts' => $settings->margin_drop_threshold_pts];
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
