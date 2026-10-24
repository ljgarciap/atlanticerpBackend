<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * Forma de pago. `metodoPagoInterno` es el valor tal cual vive en
 * `admin_contab.payments.metodo_pago` (texto libre, ver Batch 5 de Admin&Cont) — cada adapter
 * traduce ESTE valor a su propio código de proveedor. Confirmado en el comparativo de esquemas:
 * los códigos de MSL y del NUC de Digifact NO coinciden entre sí (ej. "01" es efectivo en uno y
 * crédito en el otro) — esa tabla de traducción vive siempre dentro del adapter, nunca acá.
 */
final readonly class FiscalPayment
{
    public function __construct(
        public string $metodoPagoInterno,
        public float $monto,
        public ?string $descripcion = null,
    ) {
    }
}
