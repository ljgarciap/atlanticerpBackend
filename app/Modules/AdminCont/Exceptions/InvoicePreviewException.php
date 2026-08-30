<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Exceptions;

/** REQ-441 Escenario 2 — lista los campos exactos que faltan para poder facturar una entrega. */
final class InvoicePreviewException extends \RuntimeException
{
    /** @param array<int, string> $missing */
    public function __construct(private readonly array $missing)
    {
        parent::__construct('Faltan datos para generar la factura: '.implode(', ', $missing));
    }

    /** @return array<int, string> */
    public function missing(): array
    {
        return $this->missing;
    }
}
