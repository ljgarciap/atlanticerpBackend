<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

/**
 * REQ-086/REQ-047: "Guardar borrador" y "Guardar y generar cotización" bloquean el
 * guardado (nada se persiste) cuando faltan campos obligatorios. Se lanza dentro de
 * un DB::transaction() para que Laravel haga rollback automático antes de
 * relanzarla — el controller la atrapa y responde 422 con la lista de faltantes.
 */
final class QuoteValidationBlockedException extends \RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Missing required fields to save this quote');
    }
}
