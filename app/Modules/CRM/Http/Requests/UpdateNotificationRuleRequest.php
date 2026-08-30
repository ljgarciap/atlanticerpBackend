<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use App\Modules\CRM\Models\NotificationRule;
use App\Modules\CRM\Notifications\NotifiableModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateNotificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por permission:superadmin.all en routes/admin.php
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:150'],
            'trigger_type' => ['required', Rule::in([NotificationRule::TRIGGER_MODEL_EVENT])],

            'trigger_model' => ['required_if:trigger_type,model_event', Rule::in(array_keys(NotifiableModel::REGISTRY))],
            'trigger_event' => ['required_if:trigger_type,model_event', Rule::in(['created', 'updated'])],
            'field'         => ['nullable', 'string'],
            'operator'      => ['nullable', 'required_with:field', Rule::in(NotificationRule::OPERATORS)],
            'value'         => ['nullable'],

            'channels'   => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['in_app', 'email'])],

            'recipient_type'  => ['required', Rule::in([
                NotificationRule::RECIPIENT_USER,
                NotificationRule::RECIPIENT_ROLE,
                NotificationRule::RECIPIENT_PROJECT_ASSIGNEES,
            ])],
            'recipient_value' => ['required_unless:recipient_type,project_assignees', 'array', 'min:1'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $model = $this->input('trigger_model');
            $field = $this->input('field');

            if (is_string($model) && is_string($field) && $field !== '' && ! NotifiableModel::isFieldAllowed($model, $field)) {
                $validator->errors()->add('field', "El campo [{$field}] no está permitido para el modelo [{$model}].");
            }
        });
    }
}
