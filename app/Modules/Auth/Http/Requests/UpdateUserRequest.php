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
