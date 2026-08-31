<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Compras\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-125 Escenario 2: nombre sigue siendo obligatorio al editar, no se puede vaciar.
 * `category`/`country`/al-menos-un-contacto (SCRUM-189, 2026-08-06 — hallazgo Gerencia Test) NO
 * se replican acá a propósito: el AC de Daniela habla literalmente de "crear un proveedor
 * sin..." (StoreProviderRequest); acá conviven (a) un test de regresión protegido
 * (`test_editar_proveedor_vaciando_categoria_recalcula_origin_a_internacional`, Senior Review
 * 2026-07-30) que depende de poder vaciar `category` al editar, y (b) llamadas PUT con payload
 * parcial (no siempre reenvían whatsapp/phone/email aunque el proveedor ya los tenga guardados)
 * — exigir "al menos un contacto" contra el payload de la request, no contra el estado ya
 * guardado, rompería ediciones legítimas que no tocan esos campos.
 */
class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
            'category' => ['nullable', 'string', Rule::in(Provider::CATEGORIES)],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            // SCRUM-188 — mismo regex que UpdateProfileRequest, único formato de teléfono
            // ya validado server-side en el proyecto.
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'bank_swift' => ['nullable', 'string', 'max:50'],
            'bank_beneficiary' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function uniqueNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $providerId = $this->route('id');
            $exists = Provider::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                ->where('id', '!=', $providerId)
                ->exists();
            if ($exists) {
                $fail("Ya existe un proveedor con el nombre '{$value}'.");
            }
        };
    }
}
