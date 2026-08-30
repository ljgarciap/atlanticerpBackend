<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\PricingSettingsRequest;
use App\Modules\VentasDiseno\Models\PricingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-033: CRUD de los porcentajes/divisores de las fórmulas de Tipo de Precio
 * (antes constantes PHP en QuoteItem) — visible a admin/superadmin vía el
 * permiso angosto `ventas_diseno.pricing.configure` (mismo patrón que
 * ReportsConfigController::settingsShow/settingsUpdate).
 */
final class PricingSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->format(PricingSettings::current()));
    }

    public function update(PricingSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $settings = PricingSettings::current();
        $settings->update($request->validated());

        /** @var PricingSettings $fresh */
        $fresh = $settings->fresh();

        return response()->json($this->format($fresh));
    }

    /** @return array<string, mixed> */
    private function format(PricingSettings $settings): array
    {
        return [
            'project_discount_percent'            => $settings->project_discount_percent,
            'special_additional_discount_percent' => $settings->special_additional_discount_percent,
            'partner_divisor'                     => $settings->partner_divisor,
            'premium_divisor'                     => $settings->premium_divisor,
            'min_margin_percent'                  => $settings->min_margin_percent,
        ];
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
