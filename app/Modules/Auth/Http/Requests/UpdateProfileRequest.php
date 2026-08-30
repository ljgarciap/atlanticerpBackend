<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('phone') === '') {
            $this->merge(['phone' => null]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name'    => ['sometimes', 'string', 'max:120'],
            'last_name'     => ['sometimes', 'string', 'max:120'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:1000'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'language'      => ['sometimes', 'string', 'in:es,en'],
        ];
    }
}
