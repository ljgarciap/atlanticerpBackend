<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * Línea de detalle. `tasaItbmsCode` ya viene resuelto al código DGI (00 · 01 · 02 · 03) —
 * la traducción desde `admin_contab.itbms_rates.porcentaje` la hace
 * App\Modules\AdminCont\FacturacionElectronica\Services\ItbmsCodeMapper, nunca el caller.
 */
final readonly class FiscalDocumentItem
{
    public function __construct(
        public string $codigo,
        public string $descripcion,
        public float $cantidad,
        public float $precioUnitario,
        public string $tasaItbmsCode,
        public float $descuento = 0.0,
    ) {
    }
}
