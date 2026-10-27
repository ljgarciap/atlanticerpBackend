<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\TicketTypes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-225 RN2 — "Edición global": únicamente estos 4 campos son editables desde el detalle del
 * ticket (Tipo, Subtipo, Tipo de instalación, Requerimientos especiales). Productos
 * reclamados/afectados NO está en el alcance de este endpoint (REQ-247 RN5 — solo se capturan al
 * crear el ticket; su avance de recibido/pendiente es responsabilidad de otro flujo, fuera de este
 * batch). `requerimientos_especiales` usa desde Batch 3 parte 2 el mismo formato estructurado que
 * `StoreTicketRequest` (catálogo fijo de 18 ítems + "otros" de texto libre) — antes era texto
 * libre, ver migración `2026_08_06_100000_alter_requerimientos_especiales_to_jsonb_...`.
 */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'                       => ['required', 'string', Rule::in(TicketTypes::keys())],
            'subtipo'                    => ['nullable', 'string'],
            'tipo_instalacion'           => ['required', 'string', Rule::in(Ticket::TIPOS_INSTALACION)],
            'requerimientos_especiales'         => ['nullable', 'array'],
            'requerimientos_especiales.catalog'   => ['nullable', 'array'],
            'requerimientos_especiales.catalog.*' => ['string', Rule::in(array_keys(Ticket::REQUIREMENT_LABELS))],
            'requerimientos_especiales.otros'     => ['nullable', 'array'],
            'requerimientos_especiales.otros.*'   => ['string', 'max:255'],
        ];
    }

    // Misma validación cruzada tipo/subtipo que StoreTicketRequest (REQ-279 RN4) — RN4 de REQ-225
    // ("al cambiar el Subtipo, las opciones se recalculan según el Tipo") es responsabilidad del
    // frontend al armar el formulario; acá se valida que lo que llegue sea consistente.
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $tipo    = $this->input('tipo');
            $subtipo = $this->input('subtipo');

            if (! is_string($tipo)) {
                return;
            }

            $allowedSubtipos = TicketTypes::subtipos($tipo);

            if ($allowedSubtipos === []) {
                if ($subtipo !== null && $subtipo !== '') {
                    $v->errors()->add('subtipo', 'Este tipo de ticket no maneja subtipo.');
                }
                return;
            }

            if (! is_string($subtipo) || ! in_array($subtipo, $allowedSubtipos, true)) {
                $v->errors()->add('subtipo', 'Subtipo requerido para este tipo de ticket: '.implode(', ', $allowedSubtipos).'.');
            }
        });
    }
}
