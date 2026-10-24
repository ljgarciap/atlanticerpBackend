<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-500 — payload de `PUT /api/bodega/settings`. `min:1` (nunca `min:0`) en los 5 campos:
 * `BodegaSettingsService::intSetting()` descarta silenciosamente cualquier valor persistido
 * `<= 0` y cae al default, así que un PUT con 0 debe rechazarse acá en vez de devolver 200 con
 * un valor que la siguiente lectura no respeta.
 */
class BodegaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'urgente_ventana_dias'     => ['sometimes', 'integer', 'min:1', 'max:365'],
            'pendientes_picking_horas' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'pendientes_guia_dias'     => ['sometimes', 'integer', 'min:1', 'max:365'],
            'rotacion_ventana_dias'    => ['sometimes', 'integer', 'min:1', 'max:365'],
            'rotacion_top_n'           => ['sometimes', 'integer', 'min:1', 'max:100'],
            // SCRUM-871 Pre-QA — tamaño del top de "Artículos críticos" en Home (mismo criterio
            // que rotacion_top_n: la tarjeta corta nunca muestra más de MAX_HOME_CARD_ITEMS, el
            // link "Ver más" va a Inventario paginado, no a un modal con el array completo).
            'articulos_criticos_top_n' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // SCRUM-493 (REQ-423 RN1) — umbral del aviso de concentración de "Capacidad por bodega".
            'capacidad_concentracion_pct' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // REQ-635 (SCRUM-811) — umbrales de alertas de fraude, ver
            // docs/security/THREAT-MODEL-bodega-admincont-fraude.md.
            'fraude_ajuste_grande_unidades'      => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'fraude_horario_fin_hora'            => ['sometimes', 'integer', 'min:1', 'max:23'],
            'fraude_ventana_correlacion_minutos' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'fraude_ip_lookback_dias'            => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }
}
