<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-563 — solo se pueden crear ubicaciones administrativas (`oficina`/`otra`) desde acá; las 7
 * bodegas/showrooms oficiales viven en el módulo Bodega, nunca se crean por este endpoint.
 */
class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre'    => ['required', 'string', 'max:255'],
            'tipo'      => ['required', 'in:oficina,otra'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
