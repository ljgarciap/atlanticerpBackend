<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Modules\Servicios\Models\Ticket;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-245/246/247/249 — formulario completo de "Nuevo ticket". RN4 (REQ-246) — Cliente Master/
 * Subcliente/Proyecto son obligatorios, capturados acá como `sales_project_id` (el único FK real
 * del esquema, ver docs/architecture/servicios-fase4-diseno.md sección 4); Servicios nunca valida
 * la cadena Master→Subcliente→Proyecto server-side porque el buscador en cascada del frontend ya
 * garantiza consistencia (no se puede llegar a un `sales_project_id` sin haber elegido antes un
 * Subcliente real, ver `ClientLookupService`) — la única fuente de verdad es que el proyecto
 * exista.
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'                       => ['required', 'string', Rule::in(Ticket::TIPOS)],
            'subtipo'                    => ['nullable', 'string'],
            'tipo_instalacion'           => ['required', 'string', Rule::in(Ticket::TIPOS_INSTALACION)],
            'descripcion'                => ['required', 'string'],
            // RN6 — obligatorio, existe en el catálogo real de Ventas & Diseño (tabla sin
            // calificar: el search_path del tenant ya resuelve el schema ventas_diseno).
            'sales_project_id'           => ['required', 'integer', Rule::exists('sales_projects', 'id')],
            'contacto'                   => ['nullable', 'string', 'max:255'],
            'telefono'                   => ['nullable', 'string', 'max:50'],
            'email'                      => ['nullable', 'email'],
            'direccion'                  => ['nullable', 'string'],

            // REQ-247 RN1/RN6 — catálogo fijo de 18 ítems + entradas de texto libre.
            'requerimientos_especiales'         => ['nullable', 'array'],
            'requerimientos_especiales.catalog'   => ['nullable', 'array'],
            'requerimientos_especiales.catalog.*' => ['string', Rule::in(array_keys(Ticket::REQUIREMENT_LABELS))],
            'requerimientos_especiales.otros'     => ['nullable', 'array'],
            'requerimientos_especiales.otros.*'   => ['string', 'max:255'],

            // REQ-248 RN1 — texto libre, opcional. Distinto de `descripcion` (obligatoria, "Breve
            // descripción" del bloque de datos generales) — el mockup los muestra como 2 campos
            // separados, ver docblock de la migración que agrega esta columna.
            'observaciones' => ['nullable', 'string'],

            // REQ-247 RN2/RN3/RN5 — opcional, buscado en el catálogo real, solo cantidad reclamada.
            'productos'                     => ['nullable', 'array'],
            'productos.*.catalog_product_id' => ['required', 'integer', Rule::exists('catalog_products', 'id')],
            'productos.*.cantidad_reclamo'   => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateSubtipo($v);
            $this->validateProductosNoDuplicados($v);
        });
    }

    private function validateSubtipo(Validator $v): void
    {
        $tipo    = $this->input('tipo');
        $subtipo = $this->input('subtipo');

        if (! is_string($tipo)) {
            return;
        }

        $allowedSubtipos = Ticket::SUBTIPOS_BY_TIPO[$tipo] ?? null;

        // REQ-279 RN4 — claim/retrofit no tienen subtipo, debe venir vacío.
        if ($allowedSubtipos === null) {
            if ($subtipo !== null && $subtipo !== '') {
                $v->errors()->add('subtipo', 'Este tipo de ticket no maneja subtipo.');
            }
            return;
        }

        // installation/warranty SÍ requieren subtipo, dentro del set permitido para ese tipo.
        if (! is_string($subtipo) || ! in_array($subtipo, $allowedSubtipos, true)) {
            $v->errors()->add('subtipo', 'Subtipo requerido para este tipo de ticket: '.implode(', ', $allowedSubtipos).'.');
        }
    }

    /** REQ-247 RN4 — un producto no puede aparecer 2 veces en la misma lista. */
    private function validateProductosNoDuplicados(Validator $v): void
    {
        $productos = $this->input('productos');
        if (! is_array($productos)) {
            return;
        }

        $ids = array_filter(array_column($productos, 'catalog_product_id'));
        if (count($ids) !== count(array_unique($ids))) {
            $v->errors()->add('productos', 'Un producto no puede agregarse dos veces — ajusta la cantidad en la línea ya existente.');
        }
    }
}
