<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * REQ-036: producto real del Catálogo — reference/description/brand/photo_url
 * son la "ficha técnica" (REQ-036 AC2: foto, marca, descripción, disponible,
 * precio). `price_full`/`cost` son los datos que REQ-033 necesita y que un
 * ítem personalizado nunca tuvo — al seleccionar un producto (REQ-035), estos
 * valores se COPIAN a quote_items.unit_price/cost, no se referencian en vivo.
 *
 * `provider_id`/`factory_reference`/`reorder_point` — sprint2, módulo Compras (REQ-130/131):
 * `reference` (columna original) pasa a representar la referencia PÚBLICA (única global);
 * `factory_reference` es la referencia del proveedor, única solo dentro de `provider_id` (ver
 * docs/specs/compras-proveedores-sprint2.md). El "Inventario" que piden los tickets de Compras
 * es este mismo Catálogo, no una tabla nueva.
 *
 * `import_cost`/`freight_cost`/`handling_cost`/`other_cost` (SCRUM-237, REQ-174/164): desglose de
 * costo editable en Inventario. Para proveedor no local, `GoodsReceiptController` los puebla con
 * el prorrateo real de cada Ingreso de Mercancía (REQ-164), reemplazando cualquier estimado
 * manual anterior. Para proveedor local no aplican (quedan en 0; ITBMS sigue plegado en `cost`).
 * `costTotal()` es la única fuente de verdad del margen — no volver a copiar la fórmula
 * `(price_full - cost) / price_full` en otro lugar, usar este método.
 *
 * `technical_spec` (SCRUM-425/426, REQ-355/356): ficha técnica de 12 campos libres (voltage,
 * power, socket_type, color_temperature, luminous_flux, dimensions, weight, material_finish,
 * ip_rating, estimated_lifespan, warranty, certifications), persistidos juntos como un solo JSONB.
 * Nullable — productos creados antes de este ticket no la tienen y no se backfillea con datos
 * falsos; `StoreInventoryProductRequest` la exige completa (los 12 sub-campos) solo al CREAR un
 * producto nuevo desde Compras (REQ-356 RN1/RN2), nunca retroactivo.
 *
 * `technical_sheet_key`/`technical_sheet_filename`/`technical_sheet_uploaded_at` (SCRUM-698,
 * REQ-618): ficha técnica de la pantalla Catálogo — un ARCHIVO real (PDF/PNG/JPG) en S3, concepto
 * DISTINTO de `technical_spec` de arriba (esa es data estructurada, esta es un documento). Sin
 * versionado múltiple — ver `CatalogService::uploadTechnicalSheet()`.
 *
 * `name` (SCRUM-237/240, rebotes de Gerencia Test 2026-08-12): nombre visible del producto,
 * independiente de `description`. Antes de esto `description` hacía doble función de "nombre" y
 * "descripción" — editar una cambiaba la otra en toda la app (tablas, búsqueda, PDF, Excel,
 * candidatos de sustitutos). Todo lugar que muestra/ordena/busca por el nombre del producto usa
 * `name`, nunca `description` — ver migración
 * `2026_08_15_100000_add_name_backfill_from_description_to_ventas_diseno_catalog_products_table`.
 *
 * @property int $id
 * @property string $reference
 * @property string|null $factory_reference
 * @property string $name
 * @property string $description
 * @property string|null $brand
 * @property string|null $photo_url
 * @property string|null $photo_key
 * @property string|null $photo_thumbnail_key
 * @property string|null $photo_filename
 * @property Carbon|null $photo_uploaded_at
 * @property float $price_full
 * @property float|null $cost
 * @property float $import_cost
 * @property float $freight_cost
 * @property float $handling_cost
 * @property float $other_cost
 * @property array<string, string>|null $technical_spec
 * @property int|null $stock_quantity
 * @property int|null $reorder_point
 * @property int|null $provider_id
 * @property bool $is_active
 * @property string|null $rotation
 * @property string|null $category
 * @property string|null $technical_sheet_key
 * @property string|null $technical_sheet_filename
 * @property Carbon|null $technical_sheet_uploaded_at
 * @property string|null $barcode
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CatalogProduct extends Model
{
    use LogsAuditTrail;

    public const ROTATION_ALTA = 'alta';

    public const ROTATION_MEDIA = 'media';

    public const ROTATION_BAJA = 'baja';

    // SCRUM-194 (REQ-131, mockup 2H__Compras_NuevaOrden.html) — "Compra única": producto que no
    // se reordena por patrón de rotación, se compra puntual para un proyecto específico.
    public const ROTATION_UNICA = 'unica';

    /** @var list<string> */
    public const ROTATIONS = [
        self::ROTATION_ALTA, self::ROTATION_MEDIA, self::ROTATION_BAJA, self::ROTATION_UNICA,
    ];

    /**
     * SCRUM-194 (REQ-131) — 8 valores fijos del mockup, distinto de `catalog_product_families`
     * (REQ-037: combos que se cotizan juntos, ej. "Kit Baño" — concepto no relacionado).
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'candelabros_colgantes', 'iluminacion_techo', 'apliques_pared', 'iluminacion_bano',
        'iluminacion_exterior', 'lamparas_piso_mesa', 'iluminacion_empotrada', 'bombillos',
    ];

    /**
     * SCRUM-425/426 (REQ-355/356) — los 12 sub-campos de `technical_spec`, fuente única de verdad
     * para la validación de `StoreInventoryProductRequest` y para el modal de solo lectura de
     * SCRUM-425 — no repetir esta lista en otro lugar.
     *
     * @var list<string>
     */
    public const TECHNICAL_SPEC_FIELDS = [
        'voltage', 'power', 'socket_type', 'color_temperature', 'luminous_flux', 'dimensions',
        'weight', 'material_finish', 'ip_rating', 'estimated_lifespan', 'warranty', 'certifications',
    ];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'factory_reference', 'name', 'description', 'brand', 'photo_url', 'price_full', 'cost',
        'import_cost', 'freight_cost', 'handling_cost', 'other_cost', 'technical_spec',
        'stock_quantity', 'reorder_point', 'provider_id', 'is_active', 'rotation', 'category',
        'technical_sheet_key', 'technical_sheet_filename', 'technical_sheet_uploaded_at', 'barcode',
        'photo_key', 'photo_thumbnail_key', 'photo_filename', 'photo_uploaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_full' => 'float',
            'cost' => 'float',
            'import_cost' => 'float',
            'freight_cost' => 'float',
            'handling_cost' => 'float',
            'other_cost' => 'float',
            'technical_spec' => 'array',
            'stock_quantity' => 'integer',
            'reorder_point' => 'integer',
            'is_active' => 'boolean',
            'technical_sheet_uploaded_at' => 'datetime',
            'photo_uploaded_at' => 'datetime',
        ];
    }

    /** REQ-615 RN4 — badge "✓ Ficha" / "⚠ Sin ficha". */
    public function hasTechnicalSheet(): bool
    {
        return $this->technical_sheet_key !== null;
    }

    /** SCRUM-830 — true solo si tiene imagen subida por el mecanismo nuevo (no cuenta `photo_url` legacy). */
    public function hasUploadedPhoto(): bool
    {
        return $this->photo_key !== null;
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * SCRUM-1126 — N:M: todos los proveedores del producto (el principal, `provider_id`, siempre incluido
     * vía `syncProviders()`). Los productos creados por otros caminos (orden de compra, sync ICG) que solo
     * setean `provider_id` siguen resolviéndose por `scopeForProvider()`, que también mira la columna.
     *
     * @return BelongsToMany<Provider, $this>
     */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, self::providersPivotTable(), 'catalog_product_id', 'provider_id')
            ->orderBy('name');
    }

    public static function providersPivotTable(): string
    {
        $tenant = Tenant::current();

        return $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.catalog_product_providers"
            : 'catalog_product_providers';
    }

    /**
     * SCRUM-1126 — productos disponibles para un proveedor: lo tienen como principal o en la pivote.
     *
     * @param  Builder<CatalogProduct>  $query
     * @return Builder<CatalogProduct>
     */
    public function scopeForProvider(Builder $query, int $providerId): Builder
    {
        return $query->where(function (Builder $q) use ($providerId): void {
            $q->where($this->qualifyColumn('provider_id'), $providerId)
                ->orWhereIn($this->qualifyColumn('id'), function (QueryBuilder $sub) use ($providerId): void {
                    $sub->select('catalog_product_id')->from(self::providersPivotTable())->where('provider_id', $providerId);
                });
        });
    }

    /**
     * SCRUM-1126 (Senior Review) — producto que ya usa `$factoryReference` en CUALQUIERA de `$providerIds`,
     * sea como proveedor principal o secundario (columna OR pivote). La unicidad de `factory_reference`
     * es por proveedor; con varios proveedores por producto, mirar solo la columna dejaba colar duplicados
     * dentro de la lista de Nueva Orden de un proveedor secundario.
     *
     * @param  list<int>  $providerIds
     */
    public static function findByFactoryReferenceForProviders(string $factoryReference, array $providerIds, ?int $ignoreProductId = null): ?self
    {
        if ($providerIds === []) {
            return null;
        }

        return self::query()
            ->where('factory_reference', $factoryReference)
            ->when($ignoreProductId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreProductId))
            ->where(function (Builder $q) use ($providerIds): void {
                foreach ($providerIds as $pid) {
                    $q->orWhere(fn (Builder $w) => $w->forProvider((int) $pid));
                }
            })
            ->first();
    }

    /**
     * SCRUM-1126 — fija el conjunto de proveedores y el principal. Reglas: el principal siempre está en la
     * lista; sin principal explícito se conserva el actual si sigue en la lista, si no el primero; lista
     * vacía => sin principal ni proveedores. No toca órdenes de compra existentes.
     *
     * @param  list<int>  $providerIds
     */
    public function syncProviders(array $providerIds, ?int $principalId = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $providerIds)));

        if ($principalId !== null && ! in_array($principalId, $ids, true)) {
            $ids[] = $principalId;
        }

        if ($ids === []) {
            $principalId = null;
        } elseif ($principalId === null) {
            $principalId = in_array($this->provider_id, $ids, true) ? $this->provider_id : $ids[0];
        }

        $this->providers()->sync($ids);
        if ($this->provider_id !== $principalId) {
            $this->update(['provider_id' => $principalId]);
        }
        $this->unsetRelation('providers');
    }

    /**
     * SCRUM-1126 — `[{id, name}]` con el principal primero; incluye el principal aunque la fila de la
     * pivote falte (productos creados por caminos que no la tocan).
     *
     * @return list<array{id: int, name: string}>
     */
    public function providersPayload(): array
    {
        $list = [];
        foreach ($this->providers as $p) {
            $list[$p->id] = ['id' => $p->id, 'name' => $p->name];
        }
        if ($this->provider_id !== null && ! isset($list[$this->provider_id]) && $this->relationLoaded('provider') && $this->provider !== null) {
            $list[$this->provider_id] = ['id' => $this->provider->id, 'name' => $this->provider->name];
        }
        if ($this->provider_id !== null && isset($list[$this->provider_id])) {
            $list = [$this->provider_id => $list[$this->provider_id]] + $list;
        }

        return array_values($list);
    }

    /**
     * SCRUM-237 — N:M real (un producto puede estar en varias familias), pero el modal de
     * Inventario edita una sola a la vez (ver InventoryController::update()) por simplicidad de
     * UI; esta relación se mantiene N:M para no perder membresías creadas desde otro flujo.
     *
     * @return BelongsToMany<CatalogProductFamily, $this>
     */
    public function families(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot = $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.catalog_product_family_items"
            : 'catalog_product_family_items';

        return $this->belongsToMany(CatalogProductFamily::class, $pivot, 'catalog_product_id', 'family_id');
    }

    /**
     * SCRUM-743 (REQ n/a — filtro de bodegas en Inventario) — cross-schema
     * (`ventas_diseno.catalog_products` → `bodega.product_warehouse_stock`), mismo patrón ya usado
     * en otros módulos para FKs cross-schema (ej. `sales_project_id` en Servicios). Solo usada para
     * el `whereHas()` del filtro `warehouse_ids[]` en `InventoryController::index()` — el dato en sí
     * (nombre/cantidad por bodega) lo sigue sirviendo `InventoryKpiService::warehousesMap()`, no se
     * duplica acá.
     *
     * @return HasMany<ProductWarehouseStock, $this>
     */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(ProductWarehouseStock::class, 'catalog_product_id');
    }

    public function costTotal(): float
    {
        return round(
            (float) $this->cost + $this->import_cost + $this->freight_cost
                + $this->handling_cost + $this->other_cost,
            2
        );
    }

    public function marginPercent(): ?float
    {
        if ($this->price_full <= 0) {
            return null;
        }

        return round((($this->price_full - $this->costTotal()) / $this->price_full) * 100, 2);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'catalog_products';
        }

        return "{$tenant->slug}_ventas_diseno.catalog_products";
    }

    /**
     * docs/specs/demo-mode.md — filtro de Modo Demo: solo productos con foto, nombre y precio
     * válidos. `$demoModeActive` ya viene resuelto por el caller como
     * (gate global system_settings.demo_mode_enabled) && (gate personal
     * users.preferences.demo_mode_enabled) — este scope no conoce esos 2 gates, solo aplica o no
     * aplica el filtro.
     *
     * Criterio de "tiene imagen" replica la MISMA precedencia que
     * `CatalogService::photoUrl()` (photo_key gana si existe, si no cae a photo_url) — no
     * reimplementar esa resolución en otro lugar si el criterio cambia.
     *
     * @param  Builder<CatalogProduct>  $query
     * @return Builder<CatalogProduct>
     */
    public function scopeVisibleForDemoMode(Builder $query, bool $demoModeActive): Builder
    {
        if (! $demoModeActive) {
            return $query;
        }

        return $query
            ->where('price_full', '>', 0)
            ->whereRaw("trim(coalesce(name, '')) != ''")
            ->where(function (Builder $q): void {
                $q->whereNotNull('photo_key')
                    ->orWhereRaw("trim(coalesce(photo_url, '')) != ''");
            });
    }

    /** Umbral de `word_similarity` del respaldo tolerante a typos (SCRUM-992). */
    public const SEARCH_FUZZY_THRESHOLD = 0.5;

    /**
     * SCRUM-992 (Pre-QA H18) — `random_page_cost` con el que se ejecuta la consulta de candidatos del
     * respaldo de typos. El operador `<%` SÍ puede usar el índice GIN trigram (2–15 ms con 30K filas contra
     * 95–120 ms con Seq Scan), pero con el valor por defecto (4, pensado para discos giratorios) el planner
     * estima el `Bitmap Heap Scan` más caro que leer la tabla (1115 vs 942) y elige Seq Scan. 1.1 es el valor
     * habitual para SSD/EBS gp3; solo vale dentro de la transacción de esa consulta, ver `withFuzzySettings()`.
     */
    public const SEARCH_FUZZY_RANDOM_PAGE_COST = '1.1';

    /**
     * SCRUM-992 (Pre-QA H3) — reglas del respaldo tolerante a typos, para que un término basura no
     * "encuentre" miles de filas aproximadas (`100%` -> 9.063, `x%y` -> 156, 39 letras -> 55):
     * - solo cuentan las palabras con >= SEARCH_FUZZY_MIN_WORD_LENGTH letras/dígitos (el resto —
     *   "de", "100", "x" — no aportan señal y se ignoran); sin ninguna, NO hay respaldo;
     * - los símbolos (`%`, `_`, `-`, `.`...) separan palabras, nunca cuentan como texto;
     * - una palabra de más de SEARCH_FUZZY_MAX_WORD_LENGTH caracteres es ruido: sin respaldo;
     * - máximo SEARCH_FUZZY_MAX_WORDS palabras;
     * - como mucho SEARCH_FUZZY_MAX_CANDIDATES filas, las más parecidas, nunca "todas las que pasan el umbral".
     */
    public const SEARCH_FUZZY_MIN_WORD_LENGTH = 4;

    public const SEARCH_FUZZY_MAX_WORD_LENGTH = 30;

    public const SEARCH_FUZZY_MAX_WORDS = 5;

    public const SEARCH_FUZZY_MAX_CANDIDATES = 200;

    /**
     * SCRUM-992 — búsqueda de catálogo sobre `search_text` (columna generada: referencia + referencia
     * de fábrica + nombre + marca, en minúsculas y sin acentos, con índice GIN trigram). Ver
     * docs/specs/catalog-search-performance-trgm.md. Reemplaza `->get()->all()` + `FuzzySearchService`
     * (Levenshtein en PHP, O(N) por request) en los buscadores de catálogo.
     *
     * - Cada palabra del término debe aparecer (AND), en cualquier orden: con 3+ caracteres usa
     *   `LIKE '%palabra%'` (lo resuelve el índice trigram); con menos de 3 los trigramas no ayudan y
     *   degrada a prefijo de palabra.
     * - El término se normaliza con la MISMA función SQL que la columna (`public.f_unaccent(lower())`),
     *   nunca con una transformación equivalente en PHP.
     * - `$fuzzyFallback`: SOLO si la coincidencia parcial da 0 resultados, reintenta tolerando errores
     *   de tipeo con `word_similarity` (pg_trgm). Los buscadores que pidieron coincidencia exacta/
     *   parcial sin resultados "sin relación clara" (SCRUM-796) pasan `false`.
     * - No pagina ni limita por sí mismo: el llamador SIEMPRE aplica `limit()`/`paginate()`.
     *
     * Devuelve true si se usó el respaldo tolerante a typos y encontró resultados (alimenta el flag
     * `fuzzy` de la respuesta); con 0 resultados aproximados devuelve false.
     * `$prefix` = alias de tabla con punto (ej. 'cp.') para consultas con `FROM ... cp`.
     *
     * @param  Builder<CatalogProduct>|QueryBuilder  $query
     */
    public static function applySearch(Builder|QueryBuilder $query, string $term, bool $fuzzyFallback = false, string $prefix = ''): bool
    {
        $words = self::searchWords($term);
        if ($words === []) {
            return false;
        }

        $partial = static function (Builder|QueryBuilder $q) use ($words, $prefix): void {
            foreach ($words as $word) {
                $escaped = addcslashes($word, '%_\\');
                if (mb_strlen($word) >= 3) {
                    $q->whereRaw("{$prefix}search_text LIKE '%' || public.f_unaccent(lower(?)) || '%'", [$escaped]);
                } else {
                    $q->whereRaw(
                        "({$prefix}search_text LIKE public.f_unaccent(lower(?)) || '%' OR {$prefix}search_text LIKE '% ' || public.f_unaccent(lower(?)) || '%')",
                        [$escaped, $escaped],
                    );
                }
            }
        };

        $fuzzyWords = $fuzzyFallback ? self::fuzzyWords($term) : [];
        if ($fuzzyWords !== []) {
            $probe = clone $query;
            $partial($probe);
            if (! $probe->exists()) {
                // Por palabra (todas deben parecerse a alguna palabra del texto): con la frase completa
                // dos errores de tipeo bajan `word_similarity` por debajo del umbral (0.6 por defecto).
                // SCRUM-992 (H18) — el filtro usa el OPERADOR `<%` (`palabra <% search_text` ≡
                // `word_similarity(palabra, search_text) >= umbral`, mismos resultados) porque, a diferencia
                // de la función, el operador es indexable por el GIN trigram. El umbral del operador es el
                // GUC `pg_trgm.word_similarity_threshold` (0.6 por defecto, deja fuera typos de 2 letras):
                // `withFuzzySettings()` lo fija en SEARCH_FUZZY_THRESHOLD SOLO para esta consulta.
                //
                // H3 — se evalúa UNA sola vez (antes 3 pasadas de word_similarity sobre ~30K filas) y se
                // acota a las SEARCH_FUZZY_MAX_CANDIDATES más parecidas DENTRO de las restricciones que ya
                // trae `$query` (proveedor, activo, chips...): el llamador pagina/limita ese conjunto.
                // Empate de puntaje → por id, para que el tope de candidatos sea determinista.
                $score = implode(' + ', array_fill(
                    0,
                    count($fuzzyWords),
                    "public.word_similarity(public.f_unaccent(lower(?)), {$prefix}search_text)",
                ));

                $candidates = clone $query;
                foreach ($fuzzyWords as $word) {
                    $candidates->whereRaw(
                        "public.f_unaccent(lower(?)) OPERATOR(public.<%) {$prefix}search_text",
                        [$word],
                    );
                }
                $connection = $candidates instanceof Builder ? $candidates->getQuery()->getConnection() : $candidates->getConnection();
                $ids = self::withFuzzySettings($connection, static fn (): array => $candidates->reorder()
                    ->select(["{$prefix}id"])
                    ->orderByRaw("({$score}) DESC", $fuzzyWords)
                    ->orderBy("{$prefix}id")
                    ->limit(self::SEARCH_FUZZY_MAX_CANDIDATES)
                    ->get()
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all());

                if ($ids !== []) {
                    $query->whereIntegerInRaw("{$prefix}id", $ids);

                    // `fuzzy` solo es true si el respaldo de verdad devolvió algo aproximado.
                    return true;
                }
            }
        }

        $partial($query);

        return false;
    }

    /**
     * SCRUM-992 (Pre-QA H18) — ejecuta `$fn` con los ajustes de sesión que necesita el respaldo de typos
     * (`pg_trgm.word_similarity_threshold` y `random_page_cost`), y SIN dejarlos cambiados para nadie más:
     *
     * - Va dentro de `$connection->transaction()` y usa `set_config(nombre, valor, true)` (equivale a
     *   `SET LOCAL`): el cambio dura solo hasta el fin de la transacción (COMMIT o ROLLBACK), nunca queda
     *   en la conexión — importa porque las conexiones se reutilizan entre peticiones (Octane/FPM
     *   persistente, pgbouncer) y un `SET` de sesión contaminaría consultas ajenas.
     * - Sin transacción, `set_config(..., true)` solo duraría UNA sentencia y no llegaría a la consulta.
     * - Si ya hay una transacción externa (los tests, o un llamador transaccional), `transaction()` abre un
     *   savepoint y el valor local sobreviviría hasta el fin de la EXTERNA: por eso se restauran de forma
     *   explícita los valores anteriores al terminar, con o sin error.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function withFuzzySettings(ConnectionInterface $connection, callable $fn): mixed
    {
        return $connection->transaction(function () use ($connection, $fn) {
            $settings = [
                'pg_trgm.word_similarity_threshold' => [(string) self::SEARCH_FUZZY_THRESHOLD, '0.6'],
                'random_page_cost' => [self::SEARCH_FUZZY_RANDOM_PAGE_COST, '4'],
            ];

            $previous = [];
            foreach ($settings as $name => [$value, $default]) {
                // `current_setting(x, true)` es NULL si el GUC de la extensión aún no está definido en la sesión.
                $previous[$name] = ((array) $connection->selectOne('SELECT current_setting(?, true) AS v', [$name]))['v'] ?? $default;
                $connection->select('SELECT set_config(?, ?, true)', [$name, $value]);
            }

            try {
                return $fn();
            } finally {
                try {
                    foreach ($previous as $name => $value) {
                        $connection->select('SELECT set_config(?, ?, true)', [$name, (string) $value]);
                    }
                } catch (\Throwable) {
                    // Transacción abortada por un error de la consulta: el ROLLBACK ya revierte el cambio local.
                }
            }
        });
    }

    /**
     * Orden de relevancia para un término: coincidencia exacta (referencia, referencia de fábrica o
     * nombre) > prefijo de referencia/nombre > resto. Con `$fuzzy` (respaldo tolerante a typos)
     * ordena además por `word_similarity` descendente. El llamador agrega su desempate (nombre, id).
     *
     * @param  Builder<CatalogProduct>|QueryBuilder  $query
     */
    public static function orderByRelevance(Builder|QueryBuilder $query, string $term, bool $fuzzy = false, string $prefix = ''): void
    {
        $rank = self::relevanceRank($term, $prefix);
        if ($rank === null) {
            return;
        }

        $query->orderByRaw($rank['sql'], $rank['bindings']);

        if ($fuzzy) {
            $normalized = implode(' ', self::searchWords($term));
            $query->orderByRaw("public.word_similarity(public.f_unaccent(lower(?)), {$prefix}search_text) DESC", [$normalized]);
        }
    }

    /**
     * SCRUM-996 — el `CASE` de relevancia de `orderByRelevance()` (0 = coincidencia exacta de referencia,
     * referencia de fábrica o nombre; 1 = prefijo de referencia o nombre; 2 = el resto) como expresión SQL
     * reutilizable, para consultas que NO parten de `catalog_products` (ej. el stock por bodega, que ordena
     * con una subconsulta correlacionada) sin duplicar la regla. `$prefix` = alias con punto (ej. 'cp.').
     * `null` si el término no tiene palabras.
     *
     * @return array{sql: string, bindings: list<string>}|null
     */
    public static function relevanceRank(string $term, string $prefix = ''): ?array
    {
        $normalized = implode(' ', self::searchWords($term));
        if ($normalized === '') {
            return null;
        }

        $ref = "public.f_unaccent(lower(coalesce({$prefix}reference, '')))";
        $fact = "public.f_unaccent(lower(coalesce({$prefix}factory_reference, '')))";
        $name = "public.f_unaccent(lower(coalesce({$prefix}name, '')))";
        $like = addcslashes($normalized, '%_\\');

        return [
            'sql' => "CASE WHEN {$ref} = public.f_unaccent(lower(?)) OR {$fact} = public.f_unaccent(lower(?)) OR {$name} = public.f_unaccent(lower(?)) THEN 0
                  WHEN {$ref} LIKE public.f_unaccent(lower(?)) || '%' OR {$name} LIKE public.f_unaccent(lower(?)) || '%' THEN 1
                  ELSE 2 END",
            'bindings' => [$normalized, $normalized, $normalized, $like, $like],
        ];
    }

    /**
     * Scope de conveniencia sobre `applySearch()` (sin acceso al flag de respaldo).
     *
     * @param  Builder<CatalogProduct>  $query
     * @return Builder<CatalogProduct>
     */
    public function scopeSearch(Builder $query, string $term, bool $fuzzyFallback = false): Builder
    {
        self::applySearch($query, $term, $fuzzyFallback);

        return $query;
    }

    /** @return list<string> palabras del término (separadas por espacios), sin vacíos */
    public static function searchWords(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * SCRUM-992 (H3) — palabras del término aptas para el respaldo tolerante a typos: separa por todo lo
     * que no sea letra/dígito, descarta las de menos de SEARCH_FUZZY_MIN_WORD_LENGTH, y devuelve `[]`
     * (sin respaldo) si no queda ninguna, si alguna supera SEARCH_FUZZY_MAX_WORD_LENGTH, o si el
     * término está compuesto solo por símbolos. Devuelve como máximo SEARCH_FUZZY_MAX_WORDS.
     *
     * @return list<string>
     */
    public static function fuzzyWords(string $term): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $words = [];
        foreach ($parts as $part) {
            $length = mb_strlen($part);
            if ($length > self::SEARCH_FUZZY_MAX_WORD_LENGTH) {
                return [];
            }
            if ($length >= self::SEARCH_FUZZY_MIN_WORD_LENGTH) {
                $words[] = $part;
            }
        }

        return array_slice($words, 0, self::SEARCH_FUZZY_MAX_WORDS);
    }
}
