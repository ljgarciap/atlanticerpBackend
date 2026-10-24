<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Models\User;
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
            // ADR-006, Fase D — dejó de ser obligatorio: `role_id` es el modelo real desde la
            // Fase C (el claim JWT `role` ya se resuelve desde `role_id` cuando está presente,
            // ver JwtClaimsBuilder). `roles`/`user_roles` sigue existiendo solo como fallback
            // legado para usuarios sin `role_id` (ej. registro público vía UserService::approve()).
            'roles'              => ['sometimes', 'array', 'min:1'],
            'roles.*'            => ['string', Rule::in([
                User::ROLE_SUPERADMIN,
                User::ROLE_MANAGEMENT,
                User::ROLE_DESIGNER,
                User::ROLE_SUPERVISOR,
                User::ROLE_ELECTRICIAN,
            ])],
        ];
    }

    /**
     * Fase D, blocker de Senior Review 2026-07-13 — `roles` pasó a `sometimes` (Fase D) y
     * `role_id` ya era `nullable`, así que ninguna regla exigía al menos uno de los dos. Un
     * POST /api/users sin ninguno pasaba validación y el usuario quedaba sin rol asignable
     * (`role_id` NULL, `roles` vacío), sin poder navegar a ningún módulo tras loguear.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasRoleId = $this->filled('role_id');
            $roles     = $this->input('roles');
            $hasRoles  = is_array($roles) && count($roles) > 0;

            if (! $hasRoleId && ! $hasRoles) {
                $validator->errors()->add('role_id', 'Debe asignar un rol base (role_id) o al menos un rol (roles).');
            }
        });
    }
}
