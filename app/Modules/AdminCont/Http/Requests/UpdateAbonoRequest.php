<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SCRUM-902 §2.4 — mismas reglas que el registro; el estado (por_confirmar) lo valida el
 * servicio, no este Form Request (404/422 de negocio, no de forma).
 *
 * SCRUM-955 punto 3 — misma regla nueva que `StoreAbonoRequest` (cuenta bancaria condicional por
 * método). El tope de monto contra la factura asociada (punto 1 del ticket) NO se implementa acá
 * ni en `StoreAbonoRequest` — ver el docblock de `StoreAbonoRequest` para el conflicto de negocio
 * sin resolver (SCRUM-902 §4.5, excedente de abono).
 */
class UpdateAbonoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $metodo = (string) $this->input('metodo_pago');
        $sinCuentaBancaria = in_array($metodo, AdminContPayment::METODOS_SIN_CUENTA_BANCARIA, true);

        return [
            'sub_client_id'     => ['nullable', 'integer'],
            'sales_project_id'  => ['nullable', 'integer'],
            'invoice_id'        => ['nullable', 'integer'],
            'monto_original'    => ['required', 'numeric', 'gt:0'],
            'metodo_pago'       => ['required', Rule::in(AdminContPayment::METODOS)],
            'cuenta_bancaria_id' => [Rule::requiredIf(! $sinCuentaBancaria), 'nullable', 'integer'],
            'referencia'        => ['required', 'string', 'max:255'],
            'comprobante'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('sub_client_id') && ! $this->filled('sales_project_id')) {
                $v->errors()->add('sales_project_id', 'Al elegir un sub-cliente, el proyecto se vuelve obligatorio.');
            }
        });
    }
}
