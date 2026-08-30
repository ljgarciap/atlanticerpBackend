<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\Models\AiModelPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de tarifas por modelo — superadmin ajusta precios cuando Anthropic los cambie, sin
 * deploy (regla global de no hardcodear valores de negocio, ver CLAUDE.md raíz).
 */
final class AiModelPricingController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AiModelPricing::query()->orderBy('model')->get()]);
    }

    public function update(Request $request, string $model): JsonResponse
    {
        $validated = $request->validate([
            'input_price_per_million_usd'  => ['required', 'numeric', 'min:0'],
            'output_price_per_million_usd' => ['required', 'numeric', 'min:0'],
        ]);

        $pricing = AiModelPricing::query()->find($model);

        if ($pricing === null) {
            return response()->json(['message' => 'Modelo no encontrado.'], 404);
        }

        $actorId = $request->attributes->get('jwt')['sub'] ?? null;

        $pricing->update([
            ...$validated,
            'updated_by' => $actorId !== null ? (int) $actorId : null,
            'updated_at' => now(),
        ]);

        AiModelPricing::invalidateCache($model);

        return response()->json(['data' => $pricing]);
    }
}
