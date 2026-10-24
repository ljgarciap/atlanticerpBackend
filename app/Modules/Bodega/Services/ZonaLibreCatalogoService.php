<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-990 — lectura de SOLO CONSULTA del catálogo de Lafayette (Bodega > Zona Libre) sobre `bodega.lafayette_productos`.
 * No existe ningún método de escritura: el espejo lo carga únicamente `lafayette:sync-productos`.
 *
 * Reglas de rendimiento del proyecto (~25.8K filas, `memory_limit` 256M, p95 < 500 ms, pico < 64 MB): el listado SIEMPRE
 * es una consulta paginada en SQL (nunca `get()` sin límite); la búsqueda reutiliza `CatalogProduct::applySearch()` (índice
 * GIN trigram sobre `search_text`, con respaldo tolerante a typos solo si la coincidencia parcial da 0); el resumen
 * (categorías distintas, total del catálogo y suma de disponible) es UN agregado por consulta DISTINCT/SUM y se cachea unos
 * minutos (`config('lafayette.cache_meta_segundos')`), porque solo cambia cuando corre el sincronizador (que lo invalida).
 */
final class ZonaLibreCatalogoService
{
    /** Orden permitido (lista blanca): clave del contrato de la API => columna real. */
    public const SORTS = [
        'referencia_lafayette' => 'lp.lafayette_id',
        'referencia'           => 'lp.referencia',
        'descripcion'          => 'lp.descripcion',
        'marca'                => 'lp.marca',
        'categoria'            => 'lp.categoria',
        'disponible'           => 'lp.disponible',
        'precio'               => 'lp.precio_6',
    ];

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Tope de `page` (~25.800 filas / 25 = ~1.032 páginas reales): un offset enorme no debe llegar nunca a la BD. */
    public const PAGE_MAX = 10000;

    public const DEFAULT_SORT = 'referencia';

    /** Clave de caché del resumen, por tenant; el sincronizador la invalida al terminar una corrida real. */
    public static function cacheKey(): string
    {
        return 'bodega:zona_libre:resumen:' . (Tenant::current()->slug ?? 'sin-tenant');
    }

    public static function forgetResumen(): void
    {
        Cache::forget(self::cacheKey());
    }

    /**
     * Listado paginado. `$filters`: search (texto, ya con el mínimo validado), categoria, solo_disponibles, sort, dir.
     *
     * @param array{search?: string|null, categoria?: string|null, solo_disponibles?: bool, sort?: string, dir?: string} $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function listar(array $filters, int $perPage, int $page, bool $conPrecio = true): LengthAwarePaginator
    {
        $query = $this->base();

        $categoria = $filters['categoria'] ?? null;
        if ($categoria !== null && $categoria !== '') {
            $query->where('lp.categoria', $categoria);
        }
        if (! empty($filters['solo_disponibles'])) {
            $query->where('lp.disponible', '>', 0);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $rank = null;
        if ($search !== '') {
            CatalogProduct::applySearch($query, $search, fuzzyFallback: true, prefix: 'lp.');
            $rank = $this->relevanceRank($search);
        }

        if ($rank !== null) {
            $query->orderByRaw($rank['sql'], $rank['bindings']);
        }

        $sortKey = array_key_exists($filters['sort'] ?? '', self::SORTS) ? (string) $filters['sort'] : self::DEFAULT_SORT;
        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        // NULLS LAST en ambos sentidos: un producto sin referencia/precio nunca encabeza el listado.
        $query->orderByRaw(self::SORTS[$sortKey] . " {$dir} NULLS LAST")->orderBy('lp.id');

        return $query
            ->select([
                'lp.lafayette_id', 'lp.referencia', 'lp.descripcion', 'lp.marca', 'lp.categoria', 'lp.url_imagen',
                'lp.disponible', $conPrecio ? 'lp.precio_6' : DB::raw('NULL AS precio_6'), 'lp.fuente_actualizado_at',
            ])
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Forma de UNA fila del contrato `GET /bodega/zona-libre/productos` (la consume el frontend tal cual).
     *
     * @return array{id: string, referencia_lafayette: string, referencia: string|null, descripcion: string|null, marca: string|null, categoria: string|null, url_imagen: string|null, disponible: int, precio: float|null, synced_at: string|null}
     */
    public function formatRow(\stdClass $row, bool $conPrecio = true): array
    {
        return [
            'id'                   => (string) $row->lafayette_id,
            'referencia_lafayette' => (string) $row->lafayette_id,
            'referencia'           => $row->referencia,
            'descripcion'          => $row->descripcion,
            'marca'                => $row->marca,
            'categoria'            => $row->categoria,
            'url_imagen'           => $row->url_imagen,
            'disponible'           => (int) $row->disponible,
            'precio'               => ! $conPrecio || $row->precio_6 === null ? null : (float) $row->precio_6,
            'synced_at'            => $row->fuente_actualizado_at === null
                ? null
                : \Illuminate\Support\Carbon::parse((string) $row->fuente_actualizado_at)->toIso8601String(),
        ];
    }

    /**
     * Resumen del catálogo vigente (sin los marcados fuera de la fuente): categorías distintas, total de productos y suma de
     * `disponible`. Cacheado; se invalida cuando termina una corrida del sincronizador.
     *
     * @return array{categorias: list<string>, total_catalogo: int, total_unidades: int}
     */
    public function resumen(): array
    {
        /** @var array{categorias: list<string>, total_catalogo: int, total_unidades: int} $resumen */
        $resumen = Cache::remember(self::cacheKey(), max(1, (int) config('lafayette.cache_meta_segundos', 300)), function (): array {
            $totals = $this->base()->selectRaw('COUNT(*) AS total, COALESCE(SUM(lp.disponible), 0) AS unidades')->first();

            $categorias = $this->base()
                ->whereNotNull('lp.categoria')
                ->where('lp.categoria', '<>', '')
                ->distinct()
                ->orderBy('lp.categoria')
                ->pluck('lp.categoria')
                ->map(static fn ($c): string => (string) $c)
                ->values()
                ->all();

            return [
                'categorias'     => $categorias,
                'total_catalogo' => (int) ($totals->total ?? 0),
                'total_unidades' => (int) ($totals->unidades ?? 0),
            ];
        });

        return $resumen;
    }

    /** `finished_at` (ISO 8601) de la última corrida exitosa o parcial del sincronizador, o null si nunca corrió. */
    public function ultimaSincronizacion(): ?string
    {
        $finished = LafayetteSyncRun::query()
            ->whereIn('status', [LafayetteSyncRun::STATUS_SUCCESS, LafayetteSyncRun::STATUS_PARTIAL])
            ->orderByDesc('id')
            ->value('finished_at');

        return $finished?->toIso8601String();
    }

    /** Consulta base: solo productos vigentes (los marcados fuera de la fuente se ocultan, nunca se muestran). */
    private function base(): Builder
    {
        return DB::table((new LafayetteProducto())->getTable() . ' as lp')->whereNull('lp.fuera_de_fuente_at');
    }

    /**
     * Relevancia (exacto > prefijo > resto) sobre `lafayette_id`/`referencia`/`descripcion`. No reutiliza
     * `CatalogProduct::relevanceRank()` porque esa asume las columnas `reference/factory_reference/name`.
     *
     * @return array{sql: string, bindings: list<string>}|null
     */
    private function relevanceRank(string $term): ?array
    {
        $normalized = implode(' ', CatalogProduct::searchWords($term));
        if ($normalized === '') {
            return null;
        }

        $id = "public.f_unaccent(lower(coalesce(lp.lafayette_id, '')))";
        $ref = "public.f_unaccent(lower(coalesce(lp.referencia, '')))";
        $desc = "public.f_unaccent(lower(coalesce(lp.descripcion, '')))";
        $like = addcslashes($normalized, '%_\\');

        return [
            'sql' => "CASE WHEN {$id} = public.f_unaccent(lower(?)) OR {$ref} = public.f_unaccent(lower(?)) OR {$desc} = public.f_unaccent(lower(?)) THEN 0
                      WHEN {$ref} LIKE public.f_unaccent(lower(?)) || '%' OR {$desc} LIKE public.f_unaccent(lower(?)) || '%' THEN 1
                      ELSE 2 END",
            'bindings' => [$normalized, $normalized, $normalized, $like, $like],
        ];
    }
}
