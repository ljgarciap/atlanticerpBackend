<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Http\Requests\ComprasSettingsRequest;
use App\Modules\Compras\Models\ComprasSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ComprasSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->format(ComprasSettings::current()));
    }

    public function update(ComprasSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $settings = ComprasSettings::current();
        $settings->update($request->validated());

        /** @var ComprasSettings $fresh */
        $fresh = $settings->fresh();

        return response()->json($this->format($fresh));
    }

    /**
     * Gap real encontrado por Luis (superadmin, 2026-08-22): los 5 campos `similarity_*` (Lote 4,
     * SCRUM-246) ya eran válidos en `ComprasSettingsRequest` y se persistían bien en `update()`
     * (que usa `$request->validated()` directo, no este método) — pero nunca se devolvían acá, así
     * que ninguna pantalla podía siquiera leer su valor actual, mucho menos editarlo.
     *
     * @return array<string, mixed>
     */
    private function format(ComprasSettings $settings): array
    {
        return [
            'low_rating_threshold'          => $settings->low_rating_threshold,
            'mark_approver_user_id'         => $settings->mark_approver_user_id,
            'claim_attention_days'          => $settings->claim_attention_days,
            'replacement_margin_threshold'  => $settings->replacement_margin_threshold,
            'similarity_weight_text'            => $settings->similarity_weight_text,
            'similarity_weight_price'           => $settings->similarity_weight_price,
            'similarity_weight_margin'          => $settings->similarity_weight_margin,
            'similarity_other_category_penalty' => $settings->similarity_other_category_penalty,
            'similarity_min_threshold'           => $settings->similarity_min_threshold,
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
