<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Compras\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-126 RN1 (realineado SCRUM-189, 2026-08-06 — hallazgo Gerencia Test): obligatorios
 * `name`, `category` y `country`, más al menos un medio de contacto entre
 * `whatsapp`/`phone`/`email`. Moneda por defecto USD (RN2) — se resuelve en el controller, no
 * acá, para no forzar 'USD' si el frontend manda otra cosa explícita.
 *
 * `origin` (REQ-141) YA NO se acepta del cliente (SCRUM-189, 2026-07-30): no está en el mockup
 * como campo propio, se deriva de `category` en el controller (`Provider::deriveOrigin()`).
 *
 * `category`: uno de los 5 valores fijos del mockup (`Provider::CATEGORIES`), no texto libre.
 *
 * `name` único case-insensitive (REQ-127 RN3, SCRUM-190) — hallazgo de Gerencia Test: el form
 * dejaba crear 2 proveedores con el mismo nombre.
 */
class StoreProviderRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(Provider::CATEGORIES)],
            'tax_id' => ['nullable', 'string', 'max:100', $this->uniqueTaxIdRule()],
            // SCRUM-1014 — DV del RUC, mismo formato que clientes (SCRUM-928).
            'tax_id_dv' => ['nullable', 'string', 'regex:/^\d{1,2}$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'required_without_all:phone,email', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            // SCRUM-188 — mismo regex que UpdateProfileRequest, único formato de teléfono
            // ya validado server-side en el proyecto.
            'phone' => ['nullable', 'required_without_all:whatsapp,email', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email' => ['nullable', 'required_without_all:whatsapp,phone', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'bank_swift' => ['nullable', 'string', 'max:50'],
            'bank_beneficiary' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tax_id_dv.regex' => 'El DV debe tener 1 o 2 dígitos.'];
    }

    private function uniqueNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $exists = Provider::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->exists();
            if ($exists) {
                $fail("Ya existe un proveedor con el nombre '{$value}'.");
            }
        };
    }

    /** SCRUM-852 — mismo patrón que uniqueNameRule(), bloqueo duro (misma dureza que el de nombre). */
    private function uniqueTaxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $exists = Provider::whereRaw('LOWER(tax_id) = ?', [mb_strtolower($value)])->exists();
            if ($exists) {
                $fail("Ya existe un proveedor con el identificador fiscal '{$value}'.");
            }
        };
    }
}
