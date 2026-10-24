<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-830 — reemplazo de la imagen del producto desde "Editar información". A diferencia de la
 * ficha técnica, no hay alternativa de link externo (siempre archivo) — RN07/RN09 del ticket:
 * cargar/reemplazar es siempre subir una imagen nueva.
 */
class UploadInventoryProductPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // max:10240 (10MB) — mismo límite y mismo motivo que StoreInventoryProductRequest
            // (GD decodifica el archivo completo en memoria antes de redimensionar).
            'photo' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', CatalogService::PHOTO_EXTENSIONS)],
        ];
    }
}
