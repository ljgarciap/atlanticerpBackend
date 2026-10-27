<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Http\Pagination;
use App\Shared\Http\QueryParams;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\ResizesImages;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
    /** Tope de ids de producto de las familias que coinciden con una palabra, para resolverlos como `id IN (…)`. */
    private const FAMILY_ID_LIST_MAX = 5000;

    use ResizesImages;

    private const TECHNICAL_SHEET_DISK = 's3-private';

    /** Formatos aceptados para la ficha técnica (REQ-618 RN1) — whitelist de extensiones, no mimes. */
    public const TECHNICAL_SHEET_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg'];

    /** SCRUM-830 — imagen de producto: disco público (miniatura repetida en tarjetas/listados). */
    private const PHOTO_DISK = 's3-public';

    /** SCRUM-830 — whitelist de extensiones para la imagen del producto, no mimes. */
    public const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** SCRUM-830 (RN11) — lado mayor máximo del archivo "completo" usado en el detalle. */
    private const PHOTO_MAX_DIMENSION = 1600;

    /** SCRUM-830 (RN12) — lado mayor máximo de la miniatura usada en listados/tarjetas. */
    private const PHOTO_THUMBNAIL_MAX_DIMENSION = 300;

    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
    ) {}

    /**
     * docs/specs/demo-mode.md — `$demoModeActive` ya viene resuelto por el caller
     * (`PreferencesService::isDemoModeActive()`, gate global && gate personal). Se aplica ANTES
     * de calcular `$categories`/`$families` (REQ-617 RN2/RN3) a propósito — en Modo Demo esos
     * dropdowns también deben reflejar solo el universo demo-visible, no ofrecer una categoría
     * cuyos productos están todos ocultos.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{total: int, per_page: int, current_page: int, last_page: int},
     *     categories: list<array{value: string, count: int}>,
     *     families: list<array{id: int, name: string}>,
     * }
     */
    public function list(Request $request, bool $demoModeActive = false): array
    {
        // SCRUM-992 (H1) — todo en SQL: antes `->get()->all()` de los ~30K productos con `families`,
        // 3 mapas por ID sobre todos, el filtro de texto con `str_contains` en PHP y
        // `Pagination::ofArray()` al final (2,6–4,3 s y ~176 MB de pico con `memory_limit` de 256 MB).
        // Ahora: filtros, orden y paginación en la consulta; `families` y los mapas de
        // compromiso/en camino/excluida solo para las filas de la página.
        $categories = $this->distinctActiveCategories($demoModeActive);
        $families   = $this->distinctActiveFamilies($demoModeActive);

        $query = CatalogProduct::query()
            ->from((new CatalogProduct())->getTable() . ' as cp')
            ->select('cp.*')
            ->where('cp.is_active', true)
            ->visibleForDemoMode($demoModeActive);

        // REQ-617 RN1 — texto libre, sin distinguir mayúsculas ni acentos, contra referencia + ref. de
        // fábrica + marca + nombre (`search_text`) + descripción + categoría + familia EN CONJUNTO
        // (Escenario 1: matchear el nombre de una familia trae sus productos aunque el filtro de
        // Familia no esté seleccionado). Cada palabra debe aparecer en alguno de esos campos; con una
        // sola palabra es exactamente el "contiene" de antes. Con varias, antes se buscaba la FRASE
        // completa contigua sobre el texto concatenado (accidental: cruzaba campos); ahora todas las
        // palabras en cualquier orden, igual que el resto de buscadores de catálogo de SCRUM-992.
        $search = trim(QueryParams::string($request, 'search'));
        $categoryValues = array_column($categories, 'value');
        foreach (CatalogProduct::searchWords($search) as $word) {
            $this->whereWordMatches($query, $word, $categoryValues);
        }

        $category = QueryParams::string($request, 'category');
        if ($category !== '') {
            $query->where('cp.category', $category);
        }

        $familyId = $request->query('family_id');
        if (is_string($familyId) && $familyId !== '') {
            $query->whereExists(
                fn ($q) => $q->selectRaw('1')
                    ->from($this->familyItemsTable() . ' as fi_f')
                    ->whereColumn('fi_f.catalog_product_id', 'cp.id')
                    ->where('fi_f.family_id', (int) $familyId),
            );
        }

        // REQ-617 RN4 — "Con stock disponible" / "Sin stock" / "Con o sin stock" (default, sin filtrar).
        // Mismo "disponible" que `InventoryKpiService::disponibleParaVenta()` (stock − comprometido −
        // en bodega excluida), como expresión SQL; los agregados solo se unen cuando el filtro se usa.
        $stock = QueryParams::string($request, 'stock');
        if ($stock === 'con' || $stock === 'sin') {
            $query->leftJoinSub($this->commitments->committedSub(), 'cat_cm', 'cat_cm.catalog_product_id', '=', 'cp.id');
            $this->inventoryKpis->withAggregates($query, ['en_excluida']);
            $disponible = '(COALESCE(cp.stock_quantity, 0) - COALESCE(cat_cm.total, 0) - COALESCE(inv_ex.total, 0))';
            $query->whereRaw($stock === 'con' ? "{$disponible} > 0" : "{$disponible} <= 0");
        }

        // SCRUM-754 — "Seleccionar todo" en el frontend pide `per_page=all` aparte para el universo
        // filtrado; `Pagination::params()` lo acota a MAX_ALL (1000) — techo documentado, igual que
        // antes (el slice del array también se cortaba en 1000). Orden por nombre con desempate por id
        // (antes sin desempate: filas de igual nombre podían saltar entre páginas).
        $pagination = Pagination::params($request, [], 'name');
        $paginator  = $query->orderBy('cp.name')->orderBy('cp.id')
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        /** @var list<CatalogProduct> $products */
        $products = array_values($paginator->items());
        (new EloquentCollection($products))->load('families');

        $productIds    = array_map(fn (CatalogProduct $p): int => $p->id, $products);
        $committedMap  = $this->commitments->committedMap($productIds);
        $enCaminoMap   = $this->inventoryKpis->enCaminoMap($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);
        $showroomMap   = $this->inventoryKpis->enShowroomMap($productIds);

        return [
            'data' => array_map(
                fn (CatalogProduct $p) => $this->format($p, $committedMap[$p->id] ?? 0, $enCaminoMap[$p->id] ?? 0, $enExcluidaMap[$p->id] ?? 0, $showroomMap[$p->id] ?? 0),
                $products,
            ),
            'meta'       => Pagination::meta($paginator),
            'categories' => $categories,
            'families'   => $families,
        ];
    }

    /**
     * SCRUM-1196 — «Disponible» de venta de varios productos de una vez (mismo cálculo que el catálogo y el buscador: stock − comprometido − lo que está en
     * bodegas excluidas, nunca negativo, de modo que solo cuenta Bodega Central). Lo usan las líneas de la cotización para su alerta de stock (REQ-039):
     * comparar contra el stock TOTAL (que suma Reclamos, Merma, showrooms...) dejaba a los vendedores vender stock inexistente.
     *
     * @param list<int> $productIds
     * @return array<int, int> catalog_product_id => disponible
     */
    public function availableForSaleMap(array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return [];
        }

        $committed  = $this->commitments->committedMap($productIds);
        $enExcluida = $this->inventoryKpis->enBodegaExcluidaMap($productIds);

        $map = [];
        foreach (CatalogProduct::query()->whereIn('id', $productIds)->get() as $product) {
            $map[$product->id] = max(0, (int) $this->inventoryKpis->disponibleParaVenta($product, $committed[$product->id] ?? 0, $enExcluida[$product->id] ?? 0));
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $product = CatalogProduct::with('families')->where('is_active', true)->find($id);
        if ($product === null) {
            return null;
        }

        $committed  = $this->commitments->committedMap([$id])[$id] ?? 0;
        $enCamino   = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;
        $enExcluida = $this->inventoryKpis->enBodegaExcluidaMap([$id])[$id] ?? 0;
        $showroom   = $this->inventoryKpis->enShowroomMap([$id])[$id] ?? 0;

        return $this->format($product, $committed, $enCamino, $enExcluida, $showroom);
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

    /**
     * SCRUM-830 (RN11/RN12) — sube la imagen del producto (JPG/PNG/WEBP) al disco público (a
     * diferencia de la ficha técnica: una miniatura repetida en tarjetas/listados no debe pagar
     * una presigned URL por cada request, mismo criterio ya usado para el logo de empresa en
     * `AdminContCompanyProfileController`). Genera 2 variantes con GD — original acotado a
     * ≤1600px de lado mayor y una miniatura ≤300px (mismo enfoque de `ProductoFotoConverter`,
     * migración ICG) — preservando el formato/canal alfa original (PNG/WEBP con transparencia no
     * se aplanan a blanco/negro). Mismo mecanismo de `lockForUpdate()` + limpieza post-escritura
     * que `uploadTechnicalSheet()` (ver docblock de ese método) para no dejar huérfanos en S3 ni
     * perder un archivo en una carrera de 2 uploads concurrentes.
     */
    public function uploadPhoto(CatalogProduct $product, UploadedFile $file): CatalogProduct
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $ext = in_array($ext, self::PHOTO_EXTENSIONS, true) ? $ext : 'jpg';

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('No se pudo leer el archivo de imagen subido');
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            throw new \RuntimeException('GD no pudo decodificar la imagen subida');
        }

        try {
            $mainBytes  = $this->resizeAndEncodeImage($source, $ext, self::PHOTO_MAX_DIMENSION);
            $thumbBytes = $this->resizeAndEncodeImage($source, $ext, self::PHOTO_THUMBNAIL_MAX_DIMENSION);
        } finally {
            imagedestroy($source);
        }

        $uuid         = Str::uuid()->toString();
        $key          = "catalog/{$product->id}/photo/{$uuid}.{$ext}";
        $thumbnailKey = "catalog/{$product->id}/photo/{$uuid}-thumb.{$ext}";

        (new CircuitBreaker(self::PHOTO_DISK))->call(function () use ($mainBytes, $thumbBytes, $key, $thumbnailKey): void {
            $uploadedMain  = Storage::disk(self::PHOTO_DISK)->put($key, $mainBytes);
            $uploadedThumb = Storage::disk(self::PHOTO_DISK)->put($thumbnailKey, $thumbBytes);

            if ($uploadedMain === false || $uploadedThumb === false) {
                throw new \RuntimeException("S3 upload failed for photo key [{$key}]");
            }
        });

        // Mismo criterio anti-carrera que uploadTechnicalSheet() (hallazgo Pre-QA 2026-07-31):
        // releer las keys viejas DENTRO de la transacción con lockForUpdate() antes de pisarlas.
        DB::transaction(function () use ($product, $key, $thumbnailKey, $file): void {
            $old = CatalogProduct::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first(['photo_key', 'photo_thumbnail_key']);

            $product->update([
                'photo_key'           => $key,
                'photo_thumbnail_key' => $thumbnailKey,
                'photo_filename'      => $file->getClientOriginalName(),
                'photo_uploaded_at'   => now(),
            ]);

            foreach ([$old?->photo_key, $old?->photo_thumbnail_key] as $oldKey) {
                if ($oldKey === null || in_array($oldKey, [$key, $thumbnailKey], true)) {
                    continue;
                }

                try {
                    Storage::disk(self::PHOTO_DISK)->delete($oldKey);
                } catch (\Throwable $e) {
                    Log::error('catalog_service.photo_cleanup_failed', [
                        'disk'  => self::PHOTO_DISK,
                        'key'   => $oldKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $product;
    }

    /**
     * URL pública de la imagen tamaño completo (detalle del producto). Fallback a `photo_url`
     * (string libre, poblado por el sync legacy ICG) para productos que nunca pasaron por
     * `uploadPhoto()` — nunca se fuerza un backfill retroactivo (RN del ticket aplica a
     * creación/reemplazo, no a productos viejos sin imagen que nadie edita).
     */
    public function photoUrl(CatalogProduct $product): ?string
    {
        if ($product->photo_key !== null) {
            return $this->publicPhotoUrl($product->photo_key);
        }

        return $product->photo_url;
    }

    /**
     * URL pública de la miniatura optimizada (listados/tarjetas/tablas) — RN12. Los productos
     * legacy sin `photo_thumbnail_key` no tienen una miniatura aparte generada por este mecanismo;
     * caen a `photoUrl()` (su `photo_url` ya viene razonablemente liviano desde
     * `ProductoFotoConverter`, no justifica generar una miniatura retroactiva).
     */
    public function photoThumbnailUrl(CatalogProduct $product): ?string
    {
        if ($product->photo_thumbnail_key !== null) {
            return $this->publicPhotoUrl($product->photo_thumbnail_key);
        }

        return $this->photoUrl($product);
    }

    private function publicPhotoUrl(string $key): string
    {
        $base = rtrim((string) (config('filesystems.disks.s3-public.cdn_url') ?: config('filesystems.disks.s3-public.url', '')), '/');

        return $base !== '' ? "{$base}/public/{$key}" : $key;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function format(CatalogProduct $product, int $committed, int $enCamino, int $enExcluida = 0, int $showroom = 0): array
    {
        // REQ-615 RN1/RN2 — "Disponible" ya neto del compromiso (mismo cálculo que Bodega), pero
        // acá SOLO se expone el número final: nunca `por_servir`/`por_ingresar`/`estado` internos.
        // SCRUM-851 — tampoco cuenta lo ubicado en una bodega excluida.
        $disponible = max(0, (int) $this->inventoryKpis->disponibleParaVenta($product, $committed, $enExcluida));
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
            // SCRUM-830 — miniatura optimizada (esta pantalla es un listado de tarjetas), no el
            // archivo completo. Fallback a `photo_url` legacy dentro de photoThumbnailUrl().
            'photo_url'         => $this->photoThumbnailUrl($product),
            'price_full'        => $product->price_full,
            'category'          => $product->category,
            'family_id'         => $family?->id,
            'family_name'       => $family?->name,
            // REQ-615 RN2 — stock simplificado: solo el número final + nota "en camino".
            'disponible'        => $disponible,
            // SCRUM-1196 — unidades en Showroom Obarrio: aparte del disponible (no suman), para mostrar a clientes o vender una última pieza.
            'stock_showroom'    => $showroom,
            'en_camino'         => $enCamino,
            // REQ-615 RN4 / REQ-618 RN2/RN3.
            'has_technical_sheet'        => $product->hasTechnicalSheet(),
            'technical_sheet_filename'   => $product->technical_sheet_filename,
            'technical_sheet_uploaded_at' => $product->technical_sheet_uploaded_at?->toIso8601String(),
        ];
    }

    /** Tabla pivote producto↔familia (misma que usa `CatalogProduct::families()`). */
    private function familyItemsTable(): string
    {
        $tenant = Tenant::current();

        return $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.catalog_product_family_items"
            : 'catalog_product_family_items';
    }

    /**
     * Una palabra debe aparecer (sin distinguir mayúsculas ni acentos) en `search_text` (referencia +
     * ref. de fábrica + nombre + marca), en la descripción, en la categoría o en el nombre de alguna
     * de sus familias. Se normaliza con la MISMA función SQL que `search_text`.
     *
     * Las familias y las categorías son conjuntos diminutos (decenas de filas / 8 valores): se
     * resuelve UNA vez qué familias y qué categorías coinciden con la palabra y solo se agregan como
     * `IN (...)` si hay alguna. Evaluar `f_unaccent(lower(...))` fila por fila (un `EXISTS` por
     * producto) costaba ~90 ms por consulta con 30K productos, dos veces (conteo + página).
     *
     * Mismas 8 etiquetas que `compras:newProduct.categories.*` en el frontend: acá solo importa el
     * matching (REQ-617 RN1 Escenario 1: "Modelos" matchea aunque el slug persistido sea
     * `candelabros_colgantes`), la etiqueta que ve el usuario la resuelve el frontend vía i18n.
     *
     * @param \Illuminate\Database\Eloquent\Builder<CatalogProduct> $query
     * @param list<string> $categoryValues categorías existentes del universo (slugs)
     */
    private function whereWordMatches($query, string $word, array $categoryValues): void
    {
        $needle = addcslashes($word, '%_\\');
        $like   = "'%' || public.f_unaccent(lower(?)) || '%'";

        $familyIds = DB::table((new CatalogProductFamily())->getTable())
            ->whereRaw("public.f_unaccent(lower(name)) LIKE {$like}", [$needle])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // SCRUM-992 (Pre-QA H10/H17) — las categorías que coinciden se resuelven EN POSTGRES con la MISMA
        // normalización que el resto de la búsqueda (`public.f_unaccent(lower())`, la de `search_text`, la
        // descripción y las familias). Antes se comparaba en PHP con `Str::ascii()`, que NO normaliza igual:
        // devuelve '' para '°'/'×'/emoji (`str_contains(x, '')` es siempre true → todas las categorías) y
        // descarta los símbolos que acompañan a letras ('l°' → 'l', que coincide con cualquier categoría con
        // una 'l': 25.845 en vez de 0). Son pocos valores (los slugs ya cargados para el dropdown), así que se
        // evalúan como `VALUES` en una consulta de ms, sin escanear el catálogo.
        $categories = [];
        if ($categoryValues !== []) {
            $values = implode(', ', array_fill(0, count($categoryValues), '(?)'));
            $categories = array_map(
                static fn (\stdClass $r): string => (string) $r->v,
                DB::select(
                    "SELECT v FROM (VALUES {$values}) AS t(v) WHERE public.f_unaccent(lower(replace(v, '_', ' '))) LIKE {$like}",
                    [...array_values($categoryValues), $needle],
                ),
            );
        }

        // SCRUM-992 (Pre-QA H11) — las familias que coinciden se resuelven a la lista de ids de sus
        // productos (consulta indexada, ms) y entran como `cp.id IN (1,2,…)`, indexable por la PK. Un
        // `id IN (SELECT …)` dentro de un OR queda como SubPlan, no es indexable y obligaba a Postgres
        // a leer todo el catálogo (BitmapOr solo es posible si TODAS las ramas del OR son indexables).
        // Por encima del tope (familia enorme) se conserva la subconsulta: una lista de miles de ids no
        // gana nada y el Seq Scan ya sería el plan correcto. `null` = usar la subconsulta.
        $familyProductIds = null;
        if ($familyIds !== []) {
            $ids = DB::table($this->familyItemsTable())
                ->whereIn('family_id', $familyIds)
                ->limit(self::FAMILY_ID_LIST_MAX + 1)
                ->pluck('catalog_product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $familyProductIds = count($ids) > self::FAMILY_ID_LIST_MAX ? null : $ids;
        }

        $query->where(function ($q) use ($needle, $like, $familyIds, $familyProductIds, $categories): void {
            $q->whereRaw("cp.search_text LIKE {$like}", [$needle])
                ->orWhereRaw("public.f_unaccent(lower(coalesce(cp.description, ''))) LIKE {$like}", [$needle]);

            if ($categories !== []) {
                $q->orWhereIn('cp.category', $categories);
            }
            if ($familyIds !== []) {
                if ($familyProductIds === null) {
                    $q->orWhereIn(
                        'cp.id',
                        fn ($sub) => $sub->select('catalog_product_id')->from($this->familyItemsTable())->whereIn('family_id', $familyIds),
                    );
                } elseif ($familyProductIds !== []) {
                    $q->orWhereIntegerInRaw('cp.id', $familyProductIds);
                }
            }
        });
    }

    /**
     * REQ-617 RN2/RN3 — las opciones de los dropdowns se calculan sobre el universo COMPLETO de
     * productos activos (antes de aplicar ningún filtro), mismo criterio que
     * `BodegaInventoryController::distinctActiveProviders()/distinctActiveBrands()` — si no, el
     * propio dropdown se encogería a medida que se aplican otros filtros. En SQL agrupado, sin
     * hidratar productos; orden binario (`COLLATE "C"`) como el `ksort()` de PHP de antes.
     *
     * @return list<array{value: string, count: int}>
     */
    private function distinctActiveCategories(bool $demoModeActive): array
    {
        $rows = CatalogProduct::query()
            ->from((new CatalogProduct())->getTable() . ' as cp')
            ->where('cp.is_active', true)
            ->visibleForDemoMode($demoModeActive)
            ->whereNotNull('cp.category')
            ->selectRaw('cp.category AS value, COUNT(*) AS total')
            ->groupBy('cp.category')
            ->orderByRaw('cp.category COLLATE "C"')
            ->toBase()
            ->get();

        return array_values($rows->map(fn ($r): array => ['value' => (string) $r->value, 'count' => (int) $r->total])->all());
    }

    /**
     * Familias con al menos un producto activo (y demo-visible), ordenadas por nombre en binario
     * como el `asort()` de PHP de antes, con desempate por id.
     *
     * @return list<array{id: int, name: string}>
     */
    private function distinctActiveFamilies(bool $demoModeActive): array
    {
        $visible = CatalogProduct::query()
            ->from((new CatalogProduct())->getTable() . ' as cp')
            ->where('cp.is_active', true)
            ->visibleForDemoMode($demoModeActive)
            ->select('cp.id')
            ->toBase();

        $rows = DB::table((new CatalogProductFamily())->getTable() . ' as fam')
            ->whereExists(
                fn ($q) => $q->selectRaw('1')
                    ->from($this->familyItemsTable() . ' as fi')
                    ->whereColumn('fi.family_id', 'fam.id')
                    ->whereIn('fi.catalog_product_id', $visible),
            )
            ->select('fam.id', 'fam.name')
            ->orderByRaw('fam.name COLLATE "C"')
            ->orderBy('fam.id')
            ->get();

        return array_values($rows->map(fn ($r): array => ['id' => (int) $r->id, 'name' => (string) $r->name])->all());
    }

    /**
     * SCRUM-830 — redimensiona con GD manteniendo proporción (mismo enfoque que
     * `Legacy\Services\ProductoFotoConverter::resizeAndEncode()`) y recodifica en el MISMO formato
     * de entrada (a diferencia del converter legacy, que siempre normaliza a JPEG porque su fuente
     * es BMP): preservar PNG/WEBP evita aplanar transparencia a un fondo sólido en fotos de
     * producto con canal alfa. Si ya está dentro del límite, no reescala — solo recodifica (sigue
     * pasando por compresión, útil si el original venía sin optimizar).
     */
}
