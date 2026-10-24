<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Models\User;
use App\Modules\Auth\Models\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = (int) $this->route('id');

        return [
            'first_name'          => ['sometimes', 'string', 'min:1', 'max:120'],
            'last_name'           => ['sometimes', 'string', 'min:1', 'max:120'],
            'email'               => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone'               => ['nullable', 'string', 'max:30'],
            // SCRUM-822 — mismos 3 campos que CreateUserRequest. manager_id además valida que un
            // usuario no pueda ser su propio jefe directo (a diferencia de crear, acá el usuario
            // ya tiene un id real para comparar) — closure en vez de `different:`, que solo
            // compara contra OTRO CAMPO del body, no contra el id de la ruta.
            'job_title'           => ['nullable', 'string', 'max:120'],
            'hire_date'           => ['nullable', 'date'],
            'manager_id'          => [
                'nullable', 'integer', Rule::exists('users', 'id'),
                function (string $attribute, mixed $value, \Closure $fail) use ($userId): void {
                    if ((int) $value === $userId) {
                        $fail('Un usuario no puede ser su propio jefe directo.');
                    }
                },
            ],
            'department_id'       => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'security_level_id'   => [
                'sometimes',
                'integer',
                Rule::exists('security_levels', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'extra_permissions'   => ['sometimes', 'array'],
            'extra_permissions.*' => ['string', Rule::in(PermissionCatalog::activeKeys())],
            // ADR-006, Decisión 1 revisada — ver nota en CreateUserRequest.
            'role_id'              => ['nullable', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'additional_role_ids'   => ['sometimes', 'array'],
            'additional_role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'notes'               => ['nullable', 'string'],
            'roles'               => ['sometimes', 'array', 'min:1'],
            'roles.*'             => ['string', Rule::in([
                User::ROLE_SUPERADMIN,
                User::ROLE_MANAGEMENT,
                User::ROLE_DESIGNER,
                User::ROLE_SUPERVISOR,
                User::ROLE_ELECTRICIAN,
            ])],
        ];
    }
}
