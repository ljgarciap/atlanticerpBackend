<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Http\Requests\AdminContSettingsRequest;
use App\Modules\AdminCont\Services\AdminContSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-635 (SCRUM-811) — CRUD de los 4 umbrales configurables de alertas de fraude en Admin&Cont
 * (ver `AdminContSettingsService`). Mismo patrón exacto que `BodegaSettingsController`.
 */
final class AdminContSettingsController extends Controller
{
    public function __construct(private readonly AdminContSettingsService $settings)
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

    public function update(AdminContSettingsRequest $request): JsonResponse
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
