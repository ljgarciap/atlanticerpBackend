<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\QuoteConditionsSettingsRequest;
use App\Modules\VentasDiseno\Models\QuoteConditionsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-138 — CRUD del texto global de Condiciones (REQ-046), visible/editable vía el
 * permiso individual `ventas_diseno.edit.conditions` (mismo que antes gateaba la edición
 * por-cotización, ver QuoteController). Mismo patrón que PricingSettingsController.
 */
final class QuoteConditionsSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['text' => QuoteConditionsSettings::current()->text]);
    }

    public function update(QuoteConditionsSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $settings = QuoteConditionsSettings::current();
        $settings->update($request->validated());

        /** @var QuoteConditionsSettings $fresh */
        $fresh = $settings->fresh();

        return response()->json(['text' => $fresh->text]);
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
