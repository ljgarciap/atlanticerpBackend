<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-538 — RN3: al menos 1 gasto seleccionado. RN4: forma de pago obligatoria. Que cada id siga
 * `pendiente` (sin report_id) se valida en `AdminContPettyCashService::generateReport()`, con lock
 * — un simple `exists` acá no cubre la condición de carrera de 2 reportes concurrentes.
 */
class StorePettyCashReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expense_ids'   => ['required', 'array', 'min:1'],
            'expense_ids.*' => ['integer', Rule::exists('petty_cash_expenses', 'id')],
            'forma_pago'    => ['required', 'string', Rule::in(PaymentMethods::cajaChicaKeys())],
        ];
    }
}
