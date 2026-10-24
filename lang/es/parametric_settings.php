<?php

declare(strict_types=1);

/**
 * SCRUM-944/945 (Pre-QA hallazgos A/C/E) — mensajes de validación de los valores paramétricos
 * editables desde la vista consolidada de superadmin.
 */
return [
    'tiers' => [
        'min_one'       => 'Debe existir al menos un tramo de comisión.',
        'first_zero'    => 'El primer tramo debe empezar en 0.',
        'min_gt_max'    => 'El monto mínimo no puede ser mayor que el máximo (tramo desde :min).',
        'overlap'       => 'El tramo desde :min se solapa con el tramo desde :other.',
        'gap'           => 'Hay un hueco entre el tramo que termina en :max y el tramo que empieza en :min: el siguiente mínimo debe ser :expected.',
        'last_open'     => 'El último tramo debe ser abierto (sin monto máximo).',
        'only_last_open' => 'Solo el último tramo puede ser abierto (sin monto máximo); el tramo desde :min necesita un máximo.',
        'unknown_id'    => 'El tramo con id :id no existe.',
    ],
    'crm' => [
        'freshness_order' => 'Los días de actividad "reciente" (:recent) deben ser menores que los días para considerar un proyecto "frío" (:stale).',
    ],
    // Nombres legibles de los campos del PUT atómico de tramos (Pre-QA SCRUM-945, observación BAJA 1).
    // `:row` es la fila 1-based; las CLAVES de error `tiers.N.*` no cambian.
    'attributes' => [
        'tiers'        => 'tramos',
        'row_template' => ':field (fila :row)',
        'id'           => 'id',
        'monto_minimo' => 'monto mínimo',
        'monto_maximo' => 'monto máximo',
        'porcentaje'   => 'porcentaje',
        'orden'        => 'orden',
    ],
];
