<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Http\Requests\BodegaSettingsRequest;
use App\Modules\Bodega\Services\BodegaSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-500 — CRUD de los 5 umbrales configurables consumidos por `BodegaHomeController`
 * (ver `BodegaSettingsService`). Mismo patrón de resolución de usuario vía JWT que
 * `ComprasSettingsController`; a diferencia de Compras (fila única de columnas fijas),
 * `bodega_settings` es key/value — este controller es el adaptador entre las 5 keys de negocio
 * y los 5 campos de la API.
 */
final class BodegaSettingsController extends Controller
{
    public function __construct(private readonly BodegaSettingsService $settings)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->settings->allSettings());
    }

    public function update(BodegaSettingsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $this->settings->updateSettings($request->validated());

        return response()->json($this->settings->allSettings());
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
