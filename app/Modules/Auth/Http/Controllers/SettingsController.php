<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Services\PreferencesService;
use App\Shared\Support\AnthropicClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function __construct(private readonly PreferencesService $service) {}

    // ── User preferences ──────────────────────────────────────────────────────

    public function getPreferences(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        return response()->json($this->service->getUserPreferences($user));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $data = $request->validate([
            'language'            => 'sometimes|string|in:es,en',
            'theme'               => 'sometimes|string|in:light,dark',
            'session_timeout_min' => 'sometimes|integer|in:15,30,60,120,240,480,600,1440,2880',
            'demo_mode_enabled'   => 'sometimes|boolean',
        ]);

        return response()->json($this->service->updateUserPreferences($user, $data));
    }

    // ── System settings (admin only) ──────────────────────────────────────────

    public function getSystemSettings(): JsonResponse
    {
        return response()->json($this->service->getSystemSettings());
    }

    /**
     * docs/specs/demo-mode.md — hallazgo de Frontend Dev (2026-09-04): el toggle PERSONAL de
     * Modo Demo (TopBar, cualquier usuario autenticado) necesita saber si el gate GLOBAL está
     * encendido para decidir si se muestra, pero `getSystemSettings()` de arriba está detrás de
     * `permission:settings.global` — un usuario sin ese permiso nunca vería el toggle aunque el
     * gate global esté realmente activo. Mismo patrón que `getDocumentsMaxSize()`: lectura mínima,
     * sin el resto de system_settings, accesible a cualquier autenticado.
     */
    public function getDemoModeStatus(): JsonResponse
    {
        return response()->json(['enabled' => $this->service->getSystemSettings()['demo_mode_enabled']]);
    }

    public function updateSystemSettings(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $data = $request->validate([
            'default_language'            => 'sometimes|string|in:es,en',
            'default_theme'               => 'sometimes|string|in:light,dark',
            'session_timeout_min'         => 'sometimes|integer|in:15,30,60,120,240,480,600,1440,2880',
            'mfa_mode'                    => 'sometimes|string|in:disabled,optional,required',
            'share_links_expiration_days' => 'sometimes|integer|min:1|max:365',
            'session_inactivity_hours'    => 'sometimes|integer|min:1|max:720',
            'demo_mode_enabled'           => 'sometimes|boolean',
        ]);

        return response()->json($this->service->updateSystemSettings($user, $data));
    }

    // ── AI API key (superadmin.all only) ─────────────────────────────────────

    public function getAiKey(): JsonResponse
    {
        $setting = GlobalSetting::find('anthropic_api_key');

        if ($setting === null || $setting->value === '') {
            return response()->json(['masked' => null, 'source' => 'env']);
        }

        $key    = $setting->value;
        $masked = strlen($key) > 18
            ? substr($key, 0, 14) . str_repeat('*', 10) . substr($key, -4)
            : str_repeat('*', strlen($key));

        return response()->json(['masked' => $masked, 'source' => 'database']);
    }

    public function updateAiKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'min:20', 'regex:/^sk-ant-/'],
        ]);

        $user = $this->resolveUser($request);

        GlobalSetting::updateOrCreate(
            ['key' => 'anthropic_api_key'],
            [
                'value'      => $data['value'],
                'updated_by' => $user?->id,
                'updated_at' => now(),
            ]
        );

        AnthropicClient::invalidateKeyCache();

        $masked = substr($data['value'], 0, 14) . str_repeat('*', 10) . substr($data['value'], -4);

        return response()->json(['masked' => $masked, 'source' => 'database']);
    }

    // ── Documents max size (superadmin.all only) ──────────────────────────────

    public function getDocumentsMaxSize(): JsonResponse
    {
        $setting = GlobalSetting::find('documents.max_size_mb');
        $mb      = $setting !== null && $setting->value !== ''
            ? (int) $setting->value
            : 500;

        return response()->json(['max_size_mb' => $mb]);
    }

    public function updateDocumentsMaxSize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'max_size_mb' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $user = $this->resolveUser($request);

        GlobalSetting::updateOrCreate(
            ['key' => 'documents.max_size_mb'],
            [
                'value'      => (string) $data['max_size_mb'],
                'updated_by' => $user?->id,
                'updated_at' => now(),
            ]
        );

        Cache::forget('global:documents.max_size_mb');

        return response()->json(['max_size_mb' => $data['max_size_mb']]);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');
        if ($payload === null || !isset($payload['sub'])) {
            return null;
        }
        return User::find((int) $payload['sub']);
    }
}
