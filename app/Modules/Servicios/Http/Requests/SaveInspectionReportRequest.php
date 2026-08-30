<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-238→242 — modo formulario del Informe de Inspección (JSON puro, sin archivo). El modo
 * archivo_subido (REQ-243) usa `POST .../inspection-report/upload`, validado inline en el
 * controller — ver docblock de `InspectionReportService::save()` sobre por qué están separados.
 * "Guardar informe" es una única acción, no hay borrador intermedio. RN2 (REQ-241): conclusión
 * obligatoria para completar.
 */
class SaveInspectionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_inspeccion'        => ['nullable', 'date'],
            'hora_inicio'             => ['nullable', 'string', 'max:10'],
            'hora_fin'                => ['nullable', 'string', 'max:10'],
            'internal_technician_id'  => ['nullable', 'integer'],

            'findings'                     => ['nullable', 'array'],
            'findings.*.label'              => ['required_with:findings', 'string', 'max:255'],
            'findings.*.value'              => ['nullable', 'string'],
            'findings.*.ticket_product_id'  => ['nullable', 'integer'],
            'findings.*.is_additional'      => ['nullable', 'boolean'],

            // Pre-QA (2026-08-10, SCRUM-303/REQ-240 RN2): `required_with:materiales` rechazaba con
            // 422 apenas UNA línea quedaba vacía — la UI agrega una fila en blanco por cada click
            // de "+ Agregar material usado" y manda el array completo tal cual al guardar, así que
            // el 422 era 100% alcanzable en el flujo real, no solo con un payload armado a mano.
            // `nullable` acá + el descarte silencioso ya correcto en
            // `InspectionReportService::replaceMaterials()` es lo que RN2 pide.
            'materiales.*.nombre_material'  => ['nullable', 'string', 'max:255'],
            'materiales.*.cantidad'         => ['nullable', 'string', 'max:100'],
            // Grupo D parte 2 (REQ-240 RN3/RN4, SCRUM-361) — insumo real de la Reserva Servicios,
            // opcional (ver docblock de la migración de `catalog_product_id` en
            // inspection_report_materials: aditivo, no reemplaza el texto libre). `exists`
            // sin calificar el schema — el search_path del tenant ya resuelve `catalog_products`.
            'materiales.*.catalog_product_id' => ['nullable', 'integer', 'exists:catalog_products,id'],

            // RN2 (REQ-241) — "El informe no se puede guardar como completado sin ella".
            'conclusion'              => ['required', 'string'],
            'requiere_seguimiento'    => ['nullable', 'boolean'],
            // RN3 (REQ-241) — solo se captura si requiere_seguimiento=true; el resto se ignora en el servicio.
            'proximos_pasos'          => ['nullable', 'string'],

            'firma_tecnico_nombre'    => ['nullable', 'string', 'max:255'],
            'firma_cliente_nombre'    => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // RN2 — conclusión no puede ser solo espacios.
            $conclusion = $this->input('conclusion');
            if (! is_string($conclusion) || trim($conclusion) === '') {
                $v->errors()->add('conclusion', 'La conclusión es obligatoria para completar el informe.');
            }
        });
    }
}
