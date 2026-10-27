<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Shared\Fiscal\TaxId\ValidTaxId;

/**
 * Corrección del receptor desde la vista previa de "Crear factura" (docs/specs/guarda-validacion-ruc-al-facturar.md).
 * Solo Contribuyente: marcar un cliente como Consumidor Final sigue exigiendo `ventas_diseno.edit.client_tax_data`, así
 * que este endpoint no acepta `receptor_tipo` (no hay forma de esquivar la guarda pasando a Consumidor Final).
 */
class CorrectInvoiceReceptorRequest extends FormRequest
{
    /**
     * Letras (con acentos), dígitos, espacios y la puntuación habitual de una razón social. Fuera de eso (`<`, `>`, `;`, `"`...)
     * la comparación normalizada contra la DGI dejaba pasar texto arbitrario que luego viaja a MSL y al PDF. El controller la
     * exige cuando la razón social NO es exactamente la que devolvió la DGI (SCRUM-1140: "Actualizar cliente" manda la de la DGI).
     */
    public const NOMBRE_REGEX = "/^[\\p{L}\\p{N} .,&'’()\\/#°ºª+\\-]+$/u";

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // SCRUM-1140 (Senior Review M2): acá solo se descartan `<`, `>` y caracteres de control; la regex estricta de
            // `NOMBRE_REGEX` la aplica el controller salvo que el nombre sea exactamente el que devolvió la DGI.
            'business_name' => ['required', 'string', 'max:255', 'regex:/^[^<>\\x00-\\x1F\\x7F]+$/u'],
            'tax_id'        => ['required', 'string', 'max:100', new ValidTaxId()],
            // SCRUM-1140: el DV es opcional (una cédula puede no tenerlo); la guarda lo exige solo si la DGI lo trae.
            'tax_id_dv'     => ['nullable', 'string', 'regex:/^\d{1,2}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'business_name.required' => 'La razón social es obligatoria.',
            'business_name.regex'    => 'La razón social solo admite letras, números, espacios y . , & \' ( ) / # - +.',
            'tax_id.required'        => 'El RUC o cédula es obligatorio.',
            'tax_id_dv.regex'        => 'El DV debe tener 1 o 2 dígitos.',
        ];
    }
}
