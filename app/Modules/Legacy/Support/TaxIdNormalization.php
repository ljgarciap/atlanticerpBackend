<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * SCRUM-1137 (Fase 2) — resultado de `TaxIdNormalizer::normalize()`. Valor inmutable, sin lógica.
 *
 * - `ruc`: identificador ya sin el dígito verificador (o el valor original recortado si
 *   `estado = revisar`); `null` solo cuando `origen = ninguno`.
 * - `dv`: solo dígitos, 1 a 4 caracteres, y únicamente cuando ICG lo trajo escrito con el marcador
 *   `DV`. Nunca se calcula ni se inventa.
 * - `origen`: de qué columna salió el valor (`nif20` | `cif` | `ninguno`).
 * - `estado`: `ok` | `truncado_posible` | `revisar`.
 */
final readonly class TaxIdNormalization
{
    public const ORIGEN_NIF20   = 'nif20';
    public const ORIGEN_CIF     = 'cif';
    public const ORIGEN_NINGUNO = 'ninguno';

    public const ESTADO_OK               = 'ok';
    public const ESTADO_TRUNCADO_POSIBLE = 'truncado_posible';
    public const ESTADO_REVISAR          = 'revisar';

    public function __construct(
        public ?string $ruc,
        public ?string $dv,
        public string $origen,
        public string $estado,
    ) {
    }
}
