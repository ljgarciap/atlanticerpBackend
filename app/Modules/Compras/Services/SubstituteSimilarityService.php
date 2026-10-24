<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;

/**
 * SCRUM-246 (Lote 4, comentario de Gerencia Test 2026-08-16) — "% de similitud" del método
 * "Buscar en catálogo" de Comparación de Referencias (REQ-183), único de los 3 métodos que lo
 * calcula por ahora (foto/ficha técnica quedan con el hueco documentado a propósito — capacidad de
 * IA que no existe hoy / contradicción de alcance sin resolver, ver comentario del ticket).
 *
 * Fórmula exacta especificada por Gerencia, con ejemplo numérico verificado:
 *   % = (peso_texto × similitud_texto) + (peso_precio × similitud_precio) + (peso_margen × similitud_margen)
 *       − penalización_categoría (si el candidato es de otra categoría)
 * Filtro previo: solo candidatos de la misma categoría que el original; si ninguno, se amplía a
 * todas las categorías con la penalización aplicada a cada uno. Candidatos por debajo del umbral
 * mínimo se descartan silenciosamente (RN del ticket — no tiene sentido ofrecer un sustituto poco
 * parecido como si fuera una opción real).
 *
 * Pesos/umbral SIEMPRE desde `ComprasSettings` (tabla paramétrica, Ajustes de Compras) — nunca
 * const acá, regla dura del proyecto (ver CLAUDE.md, "Nunca hardcodear umbrales de negocio").
 */
final class SubstituteSimilarityService
{
    /**
     * @param  list<CatalogProduct>  $candidates
     * @return list<array{product: CatalogProduct, similarity_percent: float, penalized: bool}>
     */
    public function rank(CatalogProduct $original, array $candidates, ?ComprasSettings $settings = null): array
    {
        $settings = $settings ?? ComprasSettings::current();

        $candidates = array_values(array_filter($candidates, fn (CatalogProduct $c): bool => $c->id !== $original->id));

        $sameCategory = array_values(array_filter(
            $candidates,
            fn (CatalogProduct $c): bool => $c->category !== null && $c->category === $original->category,
        ));
        // RN — si no hay ningún candidato en la misma categoría, se amplía a todas con penalización;
        // si SÍ hay, el pool queda acotado a esa categoría (nunca se mezclan candidatos penalizados
        // con no penalizados en la misma corrida).
        $pool = $sameCategory !== [] ? $sameCategory : $candidates;
        $penalized = $sameCategory === [];

        $ranked = [];
        foreach ($pool as $candidate) {
            $percent = $this->score($original, $candidate, $settings, $penalized);
            if ($percent < (float) $settings->similarity_min_threshold) {
                continue;
            }
            $ranked[] = ['product' => $candidate, 'similarity_percent' => $percent, 'penalized' => $penalized];
        }

        usort($ranked, fn (array $a, array $b) => $b['similarity_percent'] <=> $a['similarity_percent']);

        return $ranked;
    }

    private function score(CatalogProduct $original, CatalogProduct $candidate, ComprasSettings $settings, bool $penalized): float
    {
        $textScore = $this->textSimilarity(
            trim("{$original->name} {$original->description}"),
            trim("{$candidate->name} {$candidate->description}"),
        );
        $priceScore = $this->closenessScore($original->price_full, $candidate->price_full);
        $marginScore = $this->closenessScore($this->margin($original), $this->margin($candidate));

        $percent = ($settings->similarity_weight_text / 100 * $textScore)
            + ($settings->similarity_weight_price / 100 * $priceScore)
            + ($settings->similarity_weight_margin / 100 * $marginScore);

        if ($penalized) {
            $percent -= (float) $settings->similarity_other_category_penalty;
        }

        return round(max(0.0, $percent), 1);
    }

    /** "Método estándar de similitud de texto" (Gerencia, ej. Levenshtein) — `similar_text()` nativo de PHP. */
    private function textSimilarity(string $a, string $b): float
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent;
    }

    /** `100 − |Δ| / original × 100`, mínimo 0 — mismo cálculo para precio y margen (ejemplo de Gerencia). */
    private function closenessScore(?float $original, ?float $candidate): float
    {
        if ($original === null || $original === 0.0 || $candidate === null) {
            return 0.0;
        }

        return max(0.0, 100 - (abs($candidate - $original) / $original * 100));
    }

    private function margin(CatalogProduct $product): ?float
    {
        if ($product->price_full <= 0.0) {
            return null;
        }

        return (($product->price_full - ($product->cost ?? 0.0)) / $product->price_full) * 100;
    }
}
