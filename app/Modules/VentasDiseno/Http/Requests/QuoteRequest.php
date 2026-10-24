<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compartida entre store y update — Cotización-A no exige nada al crear (nace como
 * borrador vacío, REQ-086) ni al actualizar (todo es "sometimes"); la validación de
 * campos obligatorios recién llega con "generar" (REQ-047, Cotización-C).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // REQ-050: precarga opcional al crear — solo tiene efecto en store(), un
            // update() los recibiría pero QuoteService::update() los ignora (no están
            // en su allowlist de campos).
            'from_pipeline_card_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_cards', 'id')],
            'from_sub_client_id'    => ['sometimes', 'nullable', 'integer', Rule::exists('sub_clients', 'id')],
            'master_client_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('master_clients', 'id')],
            'sub_client_id'     => ['sometimes', 'nullable', 'integer', Rule::exists('sub_clients', 'id')],
            'sales_project_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('sales_projects', 'id')],
            'ruc'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'description'       => ['sometimes', 'nullable', 'string', 'max:255'],
            // SCRUM-1121: solo un asociado de tipo arquitecto puede ser el «Arquitecto» de la cotización (origina la comisión de Admin&Cont).
            'architect_id'      => ['sometimes', 'nullable', 'integer', Rule::exists('architects', 'id')->where('type', 'architect')],
            // SCRUM-796 (secc. 7) — 'tbd' = "Por definir": Quote::validationErrorsForGenerating()
            // no exige delivery_dates cuando el valor es este.
            'delivery_type'     => ['sometimes', 'nullable', 'string', Rule::in(['single', 'partial', 'tbd'])],
            'delivery_dates'    => ['sometimes', 'array'],
            'delivery_dates.*'  => ['date'],
            'price_type'               => ['sometimes', 'string', Rule::in(['public', 'project', 'special', 'partner', 'premium'])],
            // SCRUM-1164 — «Aplicar a todos»: junto con un price_type, quita el tipo de precio propio de cada partida para que todas usen el general.
            'clear_item_price_overrides' => ['sometimes', 'boolean'],
            'discount_mode'            => ['sometimes', 'string', Rule::in(['line', 'global'])],
            'global_discount_percent'  => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'observations'             => ['sometimes', 'nullable', 'string'],
            'includes_installation'    => ['sometimes', 'boolean'],
            'discount_totals_type'     => ['sometimes', 'string', Rule::in(['percent', 'amount'])],
            'discount_totals_value'    => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
