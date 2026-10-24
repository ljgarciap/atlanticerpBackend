<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Builder;

/**
 * SCRUM-997 (auditoría SCRUM-995, sitio F; docs/specs/scrum997-substitute-candidates-cap.md) — "Buscar Sustituto" sin texto de
 * búsqueda ya no rankea TODOS los productos del proveedor (Lafayette: ~26K, 3,4 s y 146 MB): elige en SQL los `MAX_CANDIDATES`
 * mejores candidatos y `SubstituteSimilarityService::rank()` los puntúa con su fórmula exacta, sin cambios.
 *
 * Cómo se eligen (misma fórmula que `rank()`, con los pesos de `ComprasSettings`, nunca constantes):
 *  1. Categoría: si el original tiene categoría y existe algún candidato de esa categoría, el pool se restringe a ella; si no, todos
 *     (la penalización de `rank()` es constante y no altera el orden).
 *  2. Pool de candidatos = la UNIÓN de dos generadores baratos: (a) los `POOL_BY_PRICE_MARGIN` con mayor cercanía de precio y margen al
 *     original (aritmética pura) y (b) los `POOL_BY_TEXT` más parecidos en texto según el índice trigram de `search_text` (operador `%`, ya
 *     creado por SCRUM-992). El (b) es la garantía de que un producto casi idéntico en nombre nunca queda fuera aunque su precio o margen
 *     estén lejos: en dev un candidato con nombre casi idéntico (puesto 4 del ranking completo) quedó en el puesto 6.279 por precio/margen.
 *  3. Puntaje COMBINADO `w_texto × similarity(nombre + descripción) + cercanías` sobre el pool y los `MAX_CANDIDATES` mejores.
 *
 * Medido en dev (catálogo real, 23 originales, contra el ranking completo actual): con precio/margen 4.000 solo, recall@20 medio 99,6 % pero
 * mínimo 95 % (el fallo del puesto 4); con 2.500 + 1.000 de texto (umbral 0,4) y aritmética float8 recall@10 y @20 del 100 % (mínimo 100 %), recall@50 99,9 % (mínimo
 * 98 %), con ~140 ms de SQL (máx. 239 ms; 3.000+1.000 con numeric: 190 / 289 ms). Con 1.500 + 1.000 el recall bajaba a 90-95 % (mínimo). Subir el umbral no ayudaba y bajarlo a 0,2-0,3 con más texto daba máximos de 730-850 ms. Ver la spec.
 */
final class SubstituteCandidatePreselector
{
    /** Candidatos del pool elegidos por cercanía de precio y margen. */
    public const POOL_BY_PRICE_MARGIN = 2500;

    /** Candidatos del pool elegidos por parecido de texto (índice trigram); la garantía contra dejar fuera un casi-idéntico. */
    public const POOL_BY_TEXT = 1000;

    /** Umbral de `pg_trgm.similarity_threshold` para el operador `%` del generador de texto (0,4: 2.500+1.000 dio recall 100 % en top-20). */
    private const TEXT_SIMILARITY_THRESHOLD = '0.4';

    /** Candidatos finales que entran a `rank()`; el mismo tope que ya tenía el camino con texto de búsqueda. */
    public const MAX_CANDIDATES = 500;

    /**
     * Deja `$query` (activos, y el proveedor si venía) acotado a los mejores `MAX_CANDIDATES` candidatos para `$original`, ordenados por el
     * puntaje aproximado. Reemplaza cualquier orden previo.
     *
     * @param Builder<CatalogProduct> $query
     */
    public function preselect(Builder $query, CatalogProduct $original, ?ComprasSettings $settings = null): void
    {
        $settings = $settings ?? ComprasSettings::current();

        $query->reorder()->where('id', '<>', $original->id);

        // Misma regla de categoría de `rank()`: solo si HAY candidatos de esa categoría el pool se restringe a ella.
        if ($original->category !== null && (clone $query)->where('category', $original->category)->exists()) {
            $query->where('category', $original->category);
        }

        [$closeness, $closenessBindings] = $this->closeness($original, $settings);

        // `toBase()`: el `pluck('id')` de Eloquent hidrata un modelo por fila cuando la clave primaria tiene cast (la de `CatalogProduct` lo
        // tiene), es decir miles de instancias solo para leer ids; con el query builder base no se hidrata nada.
        /** @var list<int> $priceMarginIds */
        $priceMarginIds = (clone $query)
            ->orderByRaw("{$closeness} DESC", $closenessBindings)
            ->orderBy('id')
            ->limit(self::POOL_BY_PRICE_MARGIN)
            ->toBase()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $poolIds = array_values(array_unique([...$priceMarginIds, ...$this->textCandidateIds(clone $query, $original)]));

        $textWeight = $this->weight($settings->similarity_weight_text);
        $originalText = mb_strtolower(trim("{$original->name} {$original->description}"));

        $query->whereIntegerInRaw('id', $poolIds)
            ->orderByRaw(
                "({$textWeight} * public.similarity(lower(coalesce(name, '') || ' ' || coalesce(description, '')), ?::text) + {$closeness}) DESC",
                [$originalText, ...$closenessBindings],
            )
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES);
    }

    /**
     * Los `POOL_BY_TEXT` candidatos más parecidos en texto al original, por el índice GIN trigram de `search_text` (operador `%`). Vacío si el
     * original no tiene `search_text`. El umbral de similitud se fija SOLO para esta consulta (`set_config(..., true)` dentro de una transacción)
     * y se restaura al terminar: las conexiones se reutilizan y un cambio de sesión contaminaría consultas ajenas (mismo cuidado que
     * `CatalogProduct::withFuzzySettings()`).
     *
     * @param Builder<CatalogProduct> $query
     * @return list<int>
     */
    private function textCandidateIds(Builder $query, CatalogProduct $original): array
    {
        $searchText = (string) $original->getAttribute('search_text'); // columna generada, no declarada en el modelo
        if ($searchText === '') {
            return [];
        }

        $connection = $query->getQuery()->getConnection();

        return $connection->transaction(function () use ($connection, $query, $searchText): array {
            $previous = ((array) $connection->selectOne('SELECT current_setting(?, true) AS v', ['pg_trgm.similarity_threshold']))['v'] ?? '0.3';
            $connection->select('SELECT set_config(?, ?, true)', ['pg_trgm.similarity_threshold', self::TEXT_SIMILARITY_THRESHOLD]);

            try {
                /** @var list<int> $ids */
                $ids = $query
                    ->whereRaw('search_text OPERATOR(public.%) ?', [$searchText])
                    ->orderByRaw('public.similarity(search_text, ?) DESC', [$searchText])
                    ->orderBy('id')
                    ->limit(self::POOL_BY_TEXT)
                    ->toBase()
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                return $ids;
            } finally {
                try {
                    $connection->select('SELECT set_config(?, ?, true)', ['pg_trgm.similarity_threshold', (string) $previous]);
                } catch (\Throwable) {
                    // Transacción abortada por un error de la consulta: el ROLLBACK ya revierte el cambio local.
                }
            }
        });
    }

    /**
     * `w_precio × cercanía(precio) + w_margen × cercanía(margen)` como expresión SQL, con las mismas fórmulas de
     * `SubstituteSimilarityService::closenessScore()`/`margin()`: `100 − |Δ| / original × 100`, mínimo 0, y 0 si el original no tiene precio
     * (o margen) usable. Usa `price_full` y `cost` de la fila candidata.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function closeness(CatalogProduct $original, ComprasSettings $settings): array
    {
        $price = (float) $original->price_full;
        $margin = $price > 0.0 ? ($price - (float) ($original->cost ?? 0.0)) / $price * 100 : 0.0;

        $wp = $this->weight($settings->similarity_weight_price / 100);
        $wm = $this->weight($settings->similarity_weight_margin / 100);

        // `float8` y no `numeric`: la misma cuenta sobre las ~26K filas del proveedor pasó de ~74 ms a ~25 ms (medido en dev). Es solo para
        // ORDENAR la preselección; el puntaje que ve el usuario lo calcula `rank()` en PHP.
        $sql = "({$wp} * CASE WHEN ?::float8 > 0 THEN GREATEST(0, 100 - ABS(price_full::float8 - ?::float8) / ?::float8 * 100) ELSE 0 END"
            . " + {$wm} * CASE WHEN price_full > 0 AND ?::float8 <> 0 THEN GREATEST(0, 100 - ABS(((price_full::float8 - COALESCE(cost, 0)::float8) / price_full::float8 * 100) - ?::float8) / ?::float8 * 100) ELSE 0 END)";

        return [$sql, [$price, $price, $price, $margin, $margin, $margin]];
    }

    /** Peso numérico para incrustar en el SQL (viene de `ComprasSettings`, decimal validado; nunca de la petición). */
    private function weight(float|int|string|null $value): string
    {
        return sprintf('%.6F', (float) $value);
    }
}
