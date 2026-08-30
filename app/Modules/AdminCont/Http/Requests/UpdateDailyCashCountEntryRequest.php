<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContDailyCashCountEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524 RN2) — observación editable por fila. */
class UpdateDailyCashCountEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'movement_type' => ['required', Rule::in([
                AdminContDailyCashCountEntry::TYPE_PAYMENT,
                AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE,
            ])],
            'movement_id'  => ['required', 'integer'],
            'observacion'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
