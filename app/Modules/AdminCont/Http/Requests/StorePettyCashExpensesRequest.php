<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-535 — RN1: todos los campos obligatorios, ITBMS nunca nullable (debe escribirse 0 explícito
 * — por eso `numeric` sin `nullable`, un campo vacío falla la validación). RN2: foto obligatoria
 * en cada línea, el error de Laravel para un array ya identifica el índice de la línea que falla
 * (`lineas.2.foto`), suficiente para que el frontend la señale.
 */
class StorePettyCashExpensesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lineas'                    => ['required', 'array', 'min:1'],
            'lineas.*.fecha'            => ['required', 'date'],
            'lineas.*.solicitante_id'   => ['required', 'integer', 'exists:users,id'],
            'lineas.*.proveedor'        => ['required', 'string', 'max:255'],
            'lineas.*.descripcion'      => ['required', 'string'],
            'lineas.*.monto_bruto'      => ['required', 'numeric', 'min:0'],
            'lineas.*.itbms'            => ['required', 'numeric', 'min:0'],
            'lineas.*.foto'             => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
