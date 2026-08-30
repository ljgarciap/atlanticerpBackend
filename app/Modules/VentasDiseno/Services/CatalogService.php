<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\Pagination;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-695→701 (REQ-615→621, Batch E del Epic CRM SCRUM-332) — pantalla "Catálogo": vista
 * comercial transversal (abierta desde Pipeline/Clientes, no un módulo con subtab propio) del
 * MISMO inventario real que administran Compras/Bodega (REQ-615 RN5) — ninguna tabla nueva de
 * productos, solo un subset de campos permitidos sobre `CatalogProduct`.
 *
 * Reusa EXACTAMENTE los mismos 2 servicios que `BodegaInventoryController` (`InventoryKpiService`
 * para "en camino", `OrderCommitmentService` para el compromiso que se resta del stock) — mismo
 * cálculo de "Disponible" en toda la app, nunca uno paralelo que pueda desincronizarse. La
 * diferencia real está en `format()`: acá NUNCA se expone `cost`/`import_cost`/`freight_cost`/
 * `handling_cost`/`other_cost`/`cost_total`/`margin_percent`/`por_servir`/`por_ingresar`/`estado`/
 * `rotation`/`provider_*` (REQ-615 RN1/RN2, criterio duro de seguridad de datos) — solo
 * `disponible` (ya neto del compromiso) + una nota de unidades en camino.
 *
 * SCRUM-754 (2026-08-18) — revisión de la decisión de arriba: esta pantalla SÍ pagina desde el
 * backend ahora (política global, `App\Shared\Http\Pagination`), a pedido explícito de Luis tras
 * evaluar el conflicto con RN1 de REQ-619. La resolución: "Seleccionar todo" en el frontend deja
 * de leer `data` de la respuesta paginada — hace un fetch aparte con `per_page=all` (mismos
 * filtros activos) para conseguir el universo filtrado completo, sin que este endpoint deje de
 * paginar para la navegación normal de la tabla/cuadrícula. Ver `CatalogPage.tsx` (frontend) para
 * el detalle de esa llamada separada. `categories`/`families` (dropdowns) siguen calculándose
 * sobre el universo completo de productos activos, sin cambios (REQ-617 RN2/RN3).
 */
final class CatalogService
{
    private const TECHNICAL_SHEET_DISK = 's3-private';

    /** Formatos aceptados para la ficha técnica (REQ-618 RN1) — whitelist de extensiones, no mimes. */
    public const TECHNICAL_SHEET_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg'];

    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
    ) {}

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{total: int, per_page: int, current_page: int, last_page: int},
     *     categories: list<array{value: string, count: int}>,
     *     families: list<array{id: int, name: string}>,
     * }
     */
    public function list(Request $request): array
    {
        /** @var list<CatalogProduct> $products */
        $products = CatalogProduct::query()
            ->with('families')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()->all();

        // REQ-617 RN2/RN3 — las opciones de los dropdowns se calculan sobre el universo COMPLETO
        // de productos activos (antes de aplicar ningún filtro), mismo criterio que
        // `BodegaInventoryController::distinctActiveProviders()/distinctActiveBrands()` — si no,
        // el propio dropdown se encogería a medida que se aplican otros filtros.
        $categories = $this->distinctActiveCategories($products);
        $families   = $this->distinctActiveFamilies($products);

        $productIds   = array_map(fn (CatalogProduct $p): int => $p->id, $products);
        $committedMap = $this->commitments->committedMap($productIds);
        $enCaminoMap  = $this->inventoryKpis->enCaminoMap($productIds);

        // REQ-617 RN1 — texto libre, case-insensitive, contra referencia+marca+descripción+
        // categoría+familia EN CONJUNTO (Escenario 1: matchear el nombre de una familia trae sus
        // productos aunque el filtro de Familia no esté seleccionado).
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $products = array_values(array_filter($products, function (CatalogProduct $p) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $p->reference,
                    (string) $p->factory_reference,
                    (string) $p->brand,
                    $p->name,
                    $p->description,
                    $this->categoryLabel($p->category),
                    implode(' ', $p->families->pluck('name')->all()),
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        $category = (string) $request->query('category', '');
        if ($category !== '') {
            $products = array_values(array_filter($products, fn (CatalogProduct $p): bool => $p->category === $category));
        }

        $familyId = $request->query('family_id');
        if (is_string($familyId) && $familyId !== '') {
            $products = array_values(array_filter(
                $products,
                fn (CatalogProduct $p): bool => $p->families->pluck('id')->contains((int) $familyId),
            ));
        }

        // REQ-617 RN4 — "Con stock disponible" / "Sin stock" / "Con o sin stock" (default, sin filtrar).
        $stock = (string) $request->query('stock', '');
        if ($stock === 'con' || $stock === 'sin') {
            $products = array_values(array_filter($products, function (CatalogProduct $p) use ($committedMap, $stock): bool {
                $disponible = ($p->stock_quantity ?? 0) - ($committedMap[$p->id] ?? 0);

                return $stock === 'con' ? $disponible > 0 : $disponible <= 0;
            }));
        }

        // SCRUM-754 — pagina el array ya filtrado (mismo patrón que otros listados con filtrado en
        // PHP, ver docblock de Pagination::ofArray()). "Seleccionar todo" en el frontend pide
        // per_page=all aparte para el universo filtrado completo (ver docblock de la clase).
        $pagination = Pagination::params($request, [], 'name');
        $paginator  = Pagination::ofArray($products, $pagination['per_page'], $pagination['page']);

        return [
            'data' => array_values(array_map(
                fn (CatalogProduct $p) => $this->format($p, $committedMap[$p->id] ?? 0, $enCaminoMap[$p->id] ?? 0),
                $paginator->items(),
            )),
            'meta'       => Pagination::meta($paginator),
            'categories' => $categories,
            'families'   => $families,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $product = CatalogProduct::with('families')->where('is_active', true)->find($id);
        if ($product === null) {
            return null;
        }

        $committed = $this->commitments->committedMap([$id])[$id] ?? 0;
        $enCamino  = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;

        return $this->format($product, $committed, $enCamino);
    }

    public function findModel(int $id): ?CatalogProduct
    {
        return CatalogProduct::where('is_active', true)->find($id);
    }

    /**
     * REQ-618 RN1/RN2 — sube la ficha técnica (PDF/PNG/JPG, sin límite de tamaño artificial) al
     * disco privado, mismo patrón S3 + circuit breaker que `CRM\Services\DocumentService::upload()`.
     * A diferencia de `project_documents`, sin versionado múltiple: el archivo nuevo reemplaza al
     * anterior — se borra la key vieja de S3 recién DESPUÉS de confirmar la escritura de la nueva
     * fila (mismo orden que evita perder el archivo si el borrado del viejo fallara antes).
     */
    public function uploadTechnicalSheet(CatalogProduct $product, UploadedFile $file): CatalogProduct
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $key = 'catalog/'.$product->id.'/'.Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::TECHNICAL_SHEET_DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::TECHNICAL_SHEET_DISK)->putFileAs(
                dirname($key),
                $file,
                basename($key),
            );

            if ($uploaded === false) {
                throw new \RuntimeException("S3 upload failed for technical sheet key [{$key}]");
            }
        });

        // Hallazgo de Pre-QA (2026-07-31, SCRUM-698/REQ-618) — 2 uploads concurrentes al MISMO
        // producto (doble clic antes de que el botón se deshabilite, o 2 pestañas) antes leían
        // `$product->technical_sheet_key` ANTES de que arrancara la carrera: la request que
        // terminaba de escribir en la BD primero no encontraba nada que borrar, y la segunda
        // borraba ese mismo "oldKey" pre-carrera en vez del archivo que la primera acababa de
        // subir — dejando el archivo de la primera huérfano en S3 para siempre (nunca
        // referenciado, nunca limpiado). `lockForUpdate()` serializa las 2 transacciones: la
        // segunda relee el `oldKey` ya actualizado por la primera y lo borra correctamente — al
        // final de la carrera sobrevive un solo archivo en S3, nunca 2.
        DB::transaction(function () use ($product, $key, $file): void {
            $oldKey = CatalogProduct::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->value('technical_sheet_key');

            $product->update([
                'technical_sheet_key'         => $key,
                'technical_sheet_filename'    => $file->getClientOriginalName(),
                'technical_sheet_uploaded_at' => now(),
            ]);

            if ($oldKey !== null && $oldKey !== $key) {
                try {
                    Storage::disk(self::TECHNICAL_SHEET_DISK)->delete($oldKey);
                } catch (\Throwable $e) {
                    Log::error('catalog_service.technical_sheet_cleanup_failed', [
                        'disk'  => self::TECHNICAL_SHEET_DISK,
                        'key'   => $oldKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $product;
    }

    /** REQ-618 RN2 — presigned URL de 15 min, mismo TTL que `DocumentService::PRESIGNED_URL_TTL`. */
    public function technicalSheetUrl(CatalogProduct $product): ?string
    {
        if ($product->technical_sheet_key === null) {
            return null;
        }

        return Storage::disk(self::TECHNICAL_SHEET_DISK)->temporaryUrl(
            $product->technical_sheet_key,
            now()->addMinutes(15),
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function format(CatalogProduct $product, int $committed, int $enCamino): array
    {
        // REQ-615 RN1/RN2 — "Disponible" ya neto del compromiso (mismo cálculo que Bodega), pero
        // acá SOLO se expone el número final: nunca `por_servir`/`por_ingresar`/`estado` internos.
        $disponible = max(0, ($product->stock_quantity ?? 0) - $committed);
        $family     = $product->relationLoaded('families') ? $product->families->first() : null;

        return [
            'id'                => $product->id,
            'reference'         => $product->reference,
            // Corrección 2026-08-17 — referencia real de fabricante (ver ProductoMapper), la
            // migrada desde ICG nunca fue la de `reference` (id interno estable de AtlanticERP).
            'factory_reference' => $product->factory_reference,
            'name'              => $product->name,
            'description'       => $product->description,
            'brand'             => $product->brand,
            'photo_url'         => $product->photo_url,
            'price_full'        => $product->price_full,
            'category'          => $product->category,
            'family_id'         => $family?->id,
            'family_name'       => $family?->name,
            // REQ-615 RN2 — stock simplificado: solo el número final + nota "en camino".
            'disponible'        => $disponible,
            'en_camino'         => $enCamino,
            // REQ-615 RN4 / REQ-618 RN2/RN3.
            'has_technical_sheet'        => $product->hasTechnicalSheet(),
            'technical_sheet_filename'   => $product->technical_sheet_filename,
            'technical_sheet_uploaded_at' => $product->technical_sheet_uploaded_at?->toIso8601String(),
        ];
    }

    private function categoryLabel(?string $category): string
    {
        if ($category === null) {
            return '';
        }

        // Mismas 8 etiquetas que `compras:newProduct.categories.*` en el frontend — acá solo se
        // usa para el matching de búsqueda (REQ-617 RN1 Escenario 1: "Candelabros" matchea aunque
        // el slug persistido sea `candelabros_colgantes`), la etiqueta real que ve el usuario la
        // resuelve el frontend vía i18n.
        return str_replace('_', ' ', $category);
    }

    /**
     * @param list<CatalogProduct> $products
     * @return list<array{value: string, count: int}>
     */
    private function distinctActiveCategories(array $products): array
    {
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($products as $p) {
            if ($p->category === null) {
                continue;
            }
            $counts[$p->category] = ($counts[$p->category] ?? 0) + 1;
        }

        ksort($counts);

        $result = [];
        foreach ($counts as $value => $count) {
            $result[] = ['value' => $value, 'count' => $count];
        }

        return $result;
    }

    /**
     * @param list<CatalogProduct> $products
     * @return list<array{id: int, name: string}>
     */
    private function distinctActiveFamilies(array $products): array
    {
        /** @var array<int, string> $families */
        $families = [];
        foreach ($products as $p) {
            foreach ($p->families as $family) {
                $families[$family->id] = $family->name;
            }
        }

        asort($families);

        $result = [];
        foreach ($families as $id => $name) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $result;
    }
}
