<?php

declare(strict_types=1);

/**
 * SCRUM-944/945 (Pre-QA findings A/C/E) — validation messages for the parametric values
 * editable from the consolidated superadmin view.
 */
return [
    'tiers' => [
        'min_one'       => 'At least one commission tier must exist.',
        'first_zero'    => 'The first tier must start at 0.',
        'min_gt_max'    => 'The minimum amount cannot be greater than the maximum (tier starting at :min).',
        'overlap'       => 'The tier starting at :min overlaps the tier starting at :other.',
        'gap'           => 'There is a gap between the tier ending at :max and the tier starting at :min: the next minimum must be :expected.',
        'last_open'     => 'The last tier must be open-ended (no maximum amount).',
        'only_last_open' => 'Only the last tier can be open-ended (no maximum amount); the tier starting at :min needs a maximum.',
        'unknown_id'    => 'The tier with id :id does not exist.',
    ],
    'crm' => [
        'freshness_order' => '"Recent" activity days (:recent) must be lower than the days to consider a project "stale" (:stale).',
    ],
    // Nombres legibles de los campos del PUT atómico de tramos (Pre-QA SCRUM-945, observación BAJA 1).
    // `:row` es la fila 1-based; las CLAVES de error `tiers.N.*` no cambian.
    'attributes' => [
        'tiers'        => 'tiers',
        'row_template' => ':field (row :row)',
        'id'           => 'id',
        'monto_minimo' => 'minimum amount',
        'monto_maximo' => 'maximum amount',
        'porcentaje'   => 'percentage',
        'orden'        => 'order',
    ],
];
