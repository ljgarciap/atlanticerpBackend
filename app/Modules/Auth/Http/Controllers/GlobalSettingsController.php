<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Services\OperatorReauth;
use App\Modules\Auth\Support\GlobalSettingsRegistry;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * SCRUM-944/945 (Pre-QA hallazgo E) — lectura/edición genérica de las claves de `GlobalSetting`
 * que la vista consolidada de superadmin expone (`GlobalSettingsRegistry` = lista blanca única).
 * Gateado por `permission:superadmin.all` en routes/admin.php. No toca `anthropic_api_key` ni
 * ninguna clave fuera de la lista blanca (404). Los endpoints bespoke existentes
 * (`/settings/documents/max-size`, etc.) siguen intactos.
 */
final class GlobalSettingsController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OperatorReauth $reauth,
    ) {}

    public function index(): JsonResponse
    {
        $rows = [];
        foreach (GlobalSettingsRegistry::all() as $key => $meta) {
            $rows[] = $this->present($key, $meta, $this->currentValue($key, $meta));
        }

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $meta = GlobalSettingsRegistry::find($key);
        if ($meta === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Los ajustes booleanos aceptan true/false además de 0/1 (el contrato guarda siempre 0/1).
        if ($meta['type'] === 'boolean' && is_bool($request->input('value'))) {
            $request->merge(['value' => $request->boolean('value') ? 1 : 0]);
        }

        $data = $request->validate([
            'value' => ['required', 'integer', 'min:' . ($meta['min'] ?? 0), 'max:' . ($meta['max'] ?? 0)],
        ]);
        $new = (int) $data['value'];
        $old = $this->currentValue($key, $meta);

        /** @var array{sub?: int}|null $jwt */
        $jwt = $request->attributes->get('jwt');

        // R3-6: el interruptor y la caducidad del reset administrativo exigen la contraseña del operador; el resto de ajustes no.
        // SCRUM-1158: los 5 ajustes de «Ver como usuario» también la exigen, con su propio cupo de intentos fallidos (ver `reauthScope`).
        if (GlobalSettingsRegistry::requiresReauth($key) && ($denied = $this->reauth->verify($request, isset($jwt['sub']) ? (int) $jwt['sub'] : null, GlobalSettingsRegistry::reauthScope($key))) !== null) {
            return $denied;
        }

        GlobalSetting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $new, 'updated_by' => $jwt['sub'] ?? null, 'updated_at' => now()],
        );
        Cache::forget(GlobalSettingsRegistry::cacheKey($key));

        $this->audit->log(AuditService::SETTINGS_UPDATED, 'global_setting', $key, ['value' => $old], ['value' => $new]);

        return response()->json($this->present($key, $meta, $new));
    }

    /**
     * @param array{type: string, min: int, max: int, default: int, group: string} $meta
     */
    private function currentValue(string $key, array $meta): int
    {
        $setting = GlobalSetting::find($key);
        if ($setting === null || $setting->value === '' || ! is_numeric($setting->value)) {
            return $meta['default'];
        }

        $value = (int) $setting->value;

        // Los booleanos (min 0) aceptan 0 = apagado; el resto conserva el criterio de siempre (un valor <= 0 no cuenta como guardado).
        if ($meta['min'] === 0) {
            return $value >= 0 && $value <= $meta['max'] ? $value : $meta['default'];
        }

        return $value > 0 ? $value : $meta['default'];
    }

    /**
     * @param  array{type: string, min: int, max: int, default: int, group: string}  $meta
     * @return array<string, mixed>
     */
    private function present(string $key, array $meta, int $value): array
    {
        return [
            'key'       => $key,
            'value'     => $value,
            'type'      => $meta['type'],
            'min'       => $meta['min'],
            'max'       => $meta['max'],
            'default'   => $meta['default'],
            'group'     => $meta['group'],
            'label_key' => GlobalSettingsRegistry::labelKey($key),
            'requires_reauth' => GlobalSettingsRegistry::requiresReauth($key),
        ];
    }
}
