<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\SystemSetting;

/**
 * SCRUM-739 (UAT-2) — ocultamiento MASIVO de módulos/accesos por etapa de rollout,
 * para TODOS los usuarios no-superadmin a la vez. A propósito NO reusa el
 * mecanismo de override individual de SCRUM-724 (UserModuleVisibility/
 * UserModuleMenuVisibility) — ese existe para que un admin exprese una excepción
 * puntual sobre UNA persona, y un bulk-hide/restore escribiendo ahí no puede
 * distinguir "esto lo puso el rollout" de "esto lo puso un admin por una razón
 * individual sin relación con UAT" (hallazgo real de Pre-QA en SCRUM-739, ver
 * docs/pre-qa/scrum739-bulk-module-visibility-20260807.md).
 *
 * En cambio, esto vive en `system_settings` (mismo patrón que
 * share_links_expiration_days) y se aplica como una MÁSCARA de solo lectura,
 * encima de lo que User::modulesPayload()/menuVisibilityPayload() ya calculan —
 * nunca escribe ni borra una fila de override individual. "Restaurar" es sacar
 * el módulo/ítem de esta lista, nunca tocar el dato de otro mecanismo.
 */
final class UatVisibilityService
{
    private const KEY_MODULES    = 'uat_hidden_modules';
    private const KEY_MENU_ITEMS = 'uat_hidden_menu_items';

    /** @return list<string> */
    public function hiddenModules(): array
    {
        $setting = SystemSetting::where('key', self::KEY_MODULES)->first();
        /** @var list<string> $value */
        $value = $setting !== null ? $setting->value : [];
        return $value;
    }

    /**
     * SCRUM-741 — usado por InventoryController/OrderStatusController para cortar TODO acceso no-
     * superadmin a un módulo oculto por la etapa de rollout actual, sin excepción por permiso:
     * ni la rama de permiso real (compras.read/bodega.read — hallazgo real de Pre-QA, Gerencia/Mark
     * la tienen vía su rol Gerencia) ni la rama restringida (ventas_diseno.read) dan acceso
     * mientras el módulo esté en `hiddenModules()`. La máscara UAT es cosmética para el resto de
     * la app a propósito (ver docblock de clase) — estos 2 endpoints son la única excepción
     * donde también corta acceso real, decisión explícita de Luis tras el hallazgo de Pre-QA.
     */
    public function isModuleHidden(string $module): bool
    {
        return in_array($module, $this->hiddenModules(), true);
    }

    /** @return list<array{module: string, key: string}> */
    public function hiddenMenuItems(): array
    {
        $setting = SystemSetting::where('key', self::KEY_MENU_ITEMS)->first();
        /** @var list<array{module: string, key: string}> $value */
        $value = $setting !== null ? $setting->value : [];
        return $value;
    }

    /**
     * @return array{hidden_modules: list<string>, hidden_menu_items: list<array{module: string, key: string}>}
     */
    public function currentState(): array
    {
        return ['hidden_modules' => $this->hiddenModules(), 'hidden_menu_items' => $this->hiddenMenuItems()];
    }

    /**
     * @param list<string> $modules
     * @param list<array{module: string, key: string}> $menuItems
     * @return array{hidden_modules: list<string>, hidden_menu_items: list<array{module: string, key: string}>}
     */
    public function apply(int $updatedBy, array $modules, array $menuItems, bool $hide): array
    {
        $currentModules = collect($this->hiddenModules());
        $newModules = $hide
            ? $currentModules->concat($modules)
            : $currentModules->reject(fn (string $m) => in_array($m, $modules, true));

        SystemSetting::updateOrCreate(['key' => self::KEY_MODULES], [
            'value'      => $newModules->unique()->values()->all(),
            'updated_by' => $updatedBy,
            'updated_at' => now(),
        ]);

        $currentItems = collect($this->hiddenMenuItems());
        $matches = fn (array $a, array $b) => $a['module'] === $b['module'] && $a['key'] === $b['key'];
        $newItems = $hide
            ? $currentItems->concat($menuItems)->unique(fn (array $i) => "{$i['module']}::{$i['key']}")
            : $currentItems->reject(fn (array $item) => collect($menuItems)->contains(fn (array $target) => $matches($item, $target)));

        SystemSetting::updateOrCreate(['key' => self::KEY_MENU_ITEMS], [
            'value'      => $newItems->values()->all(),
            'updated_by' => $updatedBy,
            'updated_at' => now(),
        ]);

        return $this->currentState();
    }
}
