<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContSetting;

/**
 * SCRUM-1168 F2 — configuración del concepto nuevo de cobros, en `admin_contab_settings` (tabla paramétrica del módulo; regla del proyecto:
 * ningún umbral de negocio fijo en el código).
 *
 *  - `cobros_v2_activo`: bandera por entorno. Apagada = el flujo actual de cobros y abonos tal cual; encendida = cobro con o sin factura,
 *    aplicación decidida por el usuario, compensaciones. Marcha atrás = apagarla (`php artisan cobros:v2 off`).
 *  - `compensaciones_dias_movimiento_reciente`: ventana, en días, para que un cliente sin saldo a favor siga apareciendo en Compensaciones
 *    (decisión de Luis, 2026-10-19: 30 días, configurable).
 */
final class CobrosConfigService
{
    public const KEY_V2_ACTIVO        = 'cobros_v2_activo';
    public const KEY_DIAS_MOVIMIENTO  = 'compensaciones_dias_movimiento_reciente';
    public const DIAS_MOVIMIENTO_DEFAULT = 30;

    public function v2Activo(): bool
    {
        return AdminContSetting::where('key', self::KEY_V2_ACTIVO)->value('value') === '1';
    }

    public function setV2Activo(bool $activo): void
    {
        AdminContSetting::updateOrCreate(['key' => self::KEY_V2_ACTIVO], ['value' => $activo ? '1' : '0']);
    }

    public function diasMovimientoReciente(): int
    {
        $value = (int) AdminContSetting::where('key', self::KEY_DIAS_MOVIMIENTO)->value('value');

        return $value > 0 ? $value : self::DIAS_MOVIMIENTO_DEFAULT;
    }

    public function setDiasMovimientoReciente(int $dias): void
    {
        AdminContSetting::updateOrCreate(['key' => self::KEY_DIAS_MOVIMIENTO], ['value' => (string) max(1, $dias)]);
    }
}
