<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Models\PermissionCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name'          => ['required', 'string', 'max:120'],
            'last_name'           => ['required', 'string', 'max:120'],
            'email'               => ['required', 'email', Rule::unique('users', 'email')],
            'phone'               => ['nullable', 'string', 'max:30'],
            // SCRUM-822 (REQ-548) — cargo/fecha de ingreso/jefe directo, campos del ticket
            // original que nunca se implementaron. manager_id sin `different:id` (no aplica al
            // crear, el usuario todavía no tiene id) — RN2: null = "Ninguno — Socio Director".
            'job_title'           => ['nullable', 'string', 'max:120'],
            'hire_date'           => ['nullable', 'date'],
            'manager_id'          => ['nullable', 'integer', Rule::exists('users', 'id')],
            // RN3 — estado elegible al crear, default true (comportamiento ya existente) si no se manda.
            'is_active'           => ['sometimes', 'boolean'],
            'department_id'      => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'security_level_id'  => [
                'sometimes',
                'integer',
                Rule::exists('security_levels', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'extra_permissions'   => ['sometimes', 'array'],
            'extra_permissions.*' => ['string', Rule::in(PermissionCatalog::activeKeys())],
            // ADR-006, Decisión 1 revisada — role_id es el rol base singular (SCRUM-144).
            // additional_role_ids es el 2do rol en adelante, exclusivo de superadmin — la
            // guardia real vive en UserService (silenciosamente ignorado si el actor no es
            // superadmin), esta regla solo valida la forma si el actor sí puede usarlo.
            'role_id'              => ['nullable', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'additional_role_ids'   => ['sometimes', 'array'],
            'additional_role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'notes'              => ['nullable', 'string'],
        ];
    }

    /**
     * Fase D, blocker de Senior Review 2026-07-13 — sin rol, el usuario quedaba sin poder navegar a ningún módulo tras loguear.
     * Fase 2 (2026-10-24): `role_id` es el único mecanismo (el enum legado `roles` se retiró), así que es obligatorio.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('role_id')) {
                $validator->errors()->add('role_id', 'Debe asignar un rol base (role_id).');
            }
        });
    }
}
