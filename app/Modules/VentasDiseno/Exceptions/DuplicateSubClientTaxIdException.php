<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\JsonResponse;

/**
 * Fase 17a — otro subcliente del mismo tenant ya tiene esa identificación fiscal (normalizada, con su DV). Se responde 409 con el cliente
 * existente, igual que `duplicate_exact` de los asociados, para que la pantalla ofrezca usarlo. Se lanza desde el modelo, así que cubre
 * todos los caminos de alta y edición.
 */
final class DuplicateSubClientTaxIdException extends \RuntimeException
{
    public function __construct(public readonly SubClient $existing)
    {
        parent::__construct('Ya existe un cliente con la misma identificación fiscal. Use el existente.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code'     => 'duplicate_tax_id',
            'message'  => $this->getMessage(),
            'existing' => [
                'sub_client_id'    => $this->existing->id,
                'master_client_id' => $this->existing->master_client_id,
                'business_name'    => $this->existing->business_name,
            ],
        ], 409);
    }
}
