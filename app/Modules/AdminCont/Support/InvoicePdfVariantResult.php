<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

/**
 * SCRUM-1156 — resultado de `InvoicePdfVariant::resolve()`. `consultaUrl` y `clave` solo vienen con la variante `fiscal`;
 * `motivo` solo con `sin_validez` (clave del texto que se imprime bajo «Sin validez fiscal»).
 */
final readonly class InvoicePdfVariantResult
{
    public function __construct(
        public string $variante,
        public ?string $motivo,
        public ?string $consultaUrl,
        public ?string $clave,
    ) {
    }

    public function isFiscal(): bool
    {
        return $this->variante === InvoicePdfVariant::FISCAL;
    }
}
