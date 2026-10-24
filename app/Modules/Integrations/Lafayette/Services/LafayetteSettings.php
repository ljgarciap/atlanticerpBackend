<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Services;

use App\Modules\Auth\Models\SystemSetting;

/**
 * SCRUM-990 (P2) — parámetros de negocio del sincronizador de Lafayette. Viven en `auth.system_settings` (regla del workspace: ningún umbral
 * de negocio como constante de código; la migración 2026_10_01_100300 siembra el valor por defecto) y se ajustan sin deploy. El valor por defecto de
 * respaldo (tenant sin la fila todavía) sale de `config('lafayette')`.
 *
 * `lafayette_guarda_min_ratio` (por defecto 0,9; editable por superadmin en PATCH /api/settings/system): fracción mínima de la última corrida EXITOSA que debe traer una extracción para que la
 * sincronización marque como "fuera de la fuente" los productos que no vinieron. RIESGO: con un umbral bajo (p. ej. 0,5) una vista recortada al 51 % oculta casi la mitad
 * del catálogo en Zona Libre hasta la siguiente corrida completa (reversible, no destruye datos); un valor alto (0,9) reduce esa ventana a
 * costa de más falsas alarmas cuando el catálogo de Lafayette baja de verdad. Se acota a [0, 1].
 */
final class LafayetteSettings
{
    public const GUARDA_MIN_RATIO = 'lafayette_guarda_min_ratio';

    public function guardaMinRatio(): float
    {
        $row = SystemSetting::find(self::GUARDA_MIN_RATIO);
        $value = $row?->value;
        $ratio = is_numeric($value) ? (float) $value : (float) config('lafayette.guarda_extraccion_min_ratio', 0.9);

        return max(0.0, min(1.0, $ratio));
    }
}
