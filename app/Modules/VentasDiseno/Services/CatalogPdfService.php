<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * SCRUM-702 (REQ-622) — PDF dinámico de Catálogo pensado para compartir con un cliente externo.
 * Replica el layout del PDF de ejemplo estático (`CatalogController::exampleCatalogPdf()`, REQ-621)
 * pero con datos reales: por producto, foto/referencia/marca/descripción/precio + tabla de specs
 * clave tomada de `technical_spec` (ficha técnica SCRUM-425/426) — NUNCA el archivo real de ficha
 * técnica (RN2/RN6: solo se refleja como datos, no se adjunta el documento).
 *
 * RN1 (criterio duro, no negociable) — el PDF NUNCA incluye ningún dato de stock/disponibilidad
 * (`disponible`/`en_camino`/`stock_quantity` o equivalente). A propósito `formatForPdf()` arma el
 * array desde cero seleccionando solo los campos permitidos — nunca reutiliza
 * `CatalogService::format()` (que sí incluye stock, ver su docblock) ni pasa el modelo Eloquent
 * completo a la vista, para que sea estructuralmente imposible que un campo de stock se filtre por
 * accidente a través de la Blade.
 */
final class CatalogPdfService
{
    /** Mismos 12 sub-campos de `CatalogProduct::TECHNICAL_SPEC_FIELDS`, en el mismo orden, con la
     *  etiqueta en español ya usada en `StoreInventoryProductRequest::attributes()`. */
    private const SPEC_LABELS = [
        'voltage'            => 'Voltaje',
        'power'              => 'Potencia',
        'socket_type'        => 'Tipo de casquillo',
        'color_temperature'  => 'Temperatura de color',
        'luminous_flux'      => 'Flujo luminoso',
        'dimensions'         => 'Dimensiones',
        'weight'             => 'Peso',
        'material_finish'    => 'Material / acabado',
        'ip_rating'          => 'Índice de protección (IP)',
        'estimated_lifespan' => 'Vida útil estimada',
        'warranty'           => 'Garantía',
        'certifications'     => 'Certificaciones',
    ];

    /**
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds Ignorado cuando $mode === 'completo' (RN4).
     */
    public function generate(string $mode, array $productIds = []): PdfDocument
    {
        $products = $this->resolveProducts($mode, $productIds);
        $rows     = array_map(fn (CatalogProduct $p) => $this->formatForPdf($p), $products);

        return Pdf::loadView('ventas-diseno.catalog-pdf', [
            'products'     => $rows,
            'generatedAt'  => now(),
        ])->setPaper('letter');
    }

    /**
     * @param list<int> $productIds
     * @return list<CatalogProduct>
     */
    private function resolveProducts(string $mode, array $productIds): array
    {
        $query = CatalogProduct::query()->where('is_active', true)->orderBy('name');

        // RN4 — "completo" trae TODOS los productos activos, ignorando cualquier
        // selección/filtro recibido; solo "seleccionados" acota por product_ids.
        if ($mode === 'seleccionados') {
            $query->whereIn('id', $productIds);
        }

        return array_values($query->get()->all());
    }

    /**
     * Array explícito de campos permitidos — ver docblock de la clase (RN1). Nunca incluir
     * `stock_quantity`/`disponible`/`en_camino`/`cost`/`margin_percent` ni ningún otro campo
     * interno acá.
     *
     * @return array<string, mixed>
     */
    private function formatForPdf(CatalogProduct $product): array
    {
        return [
            'id'          => $product->id,
            'reference'   => $product->reference,
            'name'        => $product->name,
            'description' => $product->description,
            'brand'       => $product->brand,
            'photo_url'   => $product->photo_url,
            'price_full'  => $product->price_full,
            'category'    => $product->category,
            'specs'       => $this->formatSpecs($product->technical_spec),
        ];
    }

    /**
     * @param array<string, string>|null $technicalSpec
     * @return list<array{label: string, value: string}>
     */
    private function formatSpecs(?array $technicalSpec): array
    {
        if ($technicalSpec === null) {
            return [];
        }

        $result = [];
        foreach (CatalogProduct::TECHNICAL_SPEC_FIELDS as $field) {
            $value = $technicalSpec[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $result[] = ['label' => self::SPEC_LABELS[$field], 'value' => $value];
        }

        return $result;
    }
}
