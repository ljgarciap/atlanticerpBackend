<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-226 — Agendar/Reagendar. RN6: fecha Y hora son obligatorias (no se puede agendar sin hora
 * definida) — el frontend combina ambas en un único `scheduled_at` ISO vía selectores reales de
 * calendario/hora (RN3: nunca texto libre). `internal_technician_id` requerido — RN2 (filtro de
 * técnico por especialidad) se valida en `TicketService::schedule()`, no acá, porque necesita
 * conocer el `tipo` del ticket ya persistido.
 *
 * `motivo_reagendamiento` (SCRUM-804) — `nullable` acá a propósito: si es obligatorio depende de
 * si ES un reagendamiento (el ticket ya tenía fecha), algo que solo se sabe con el ticket ya
 * persistido — mismo criterio que RN2, se valida en `TicketService::schedule()`, no acá.
 */
class ScheduleTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'internal_technician_id' => ['required', 'integer'],
            'scheduled_at'           => ['required', 'date'],
            'scheduled_ends_at'      => ['nullable', 'date', 'after:scheduled_at'],
            'motivo_reagendamiento'  => ['nullable', 'string', 'max:500'],
        ];
    }
}
