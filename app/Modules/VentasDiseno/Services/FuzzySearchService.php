<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

/**
 * Búsqueda exacta con fallback aproximado (Levenshtein), extraída de
 * QuoteListService (REQ-081/SCRUM-103) para reusarla también en el buscador
 * de Catálogo (REQ-036) — mismo mecanismo, dos consumidores.
 *
 * Primero coincidencia exacta (texto contenido en el haystack); si no hay
 * ninguna, hasta `$limit` resultados aproximados por distancia de edición. El
 * término buscado se tokeniza igual que el haystack — cada palabra del
 * término se compara contra la palabra más cercana del haystack, no el
 * término completo contra cada palabra suelta, para que un típo en una sola
 * palabra de una búsqueda de varias palabras no infle la distancia con el
 * resto de las palabras que sí calzan exacto (bug real de QA formal,
 * 2026-07-12, SCRUM-103).
 */
final class FuzzySearchService
{
    private const DEFAULT_LIMIT = 5;

    /**
     * @template T
     * @param list<T> $rows
     * @param callable(T): string $textExtractor
     * @return array{0: list<T>, 1: bool}
     */
    public function search(array $rows, string $term, callable $textExtractor, int $limit = self::DEFAULT_LIMIT): array
    {
        $term = trim($term);

        $exact = array_values(array_filter(
            $rows,
            fn ($r): bool => stripos($textExtractor($r), $term) !== false,
        ));
        if ($exact !== []) {
            return [$exact, false];
        }

        $needleWords = array_values(array_filter(preg_split('/\s+/', mb_strtolower($term)) ?: []));
        if ($needleWords === []) {
            return [[], false];
        }

        $distances = [];
        foreach ($rows as $i => $r) {
            $haystack        = $textExtractor($r);
            $haystackWords   = array_filter(preg_split('/\s+/', $haystack) ?: []);
            $haystackWords[] = $haystack;
            $haystackWords   = array_map(fn (string $w) => mb_strtolower($w), $haystackWords);

            $totalDistance = 0;
            foreach ($needleWords as $word) {
                $maxDistance = max(2, (int) floor(mb_strlen($word) / 3));
                $best        = min(array_map(fn (string $w) => levenshtein($word, $w), $haystackWords));

                if ($best > $maxDistance) {
                    continue 2;
                }
                $totalDistance += $best;
            }

            $distances[$i] = $totalDistance;
        }

        asort($distances);
        $indexes = array_slice(array_keys($distances), 0, $limit);
        $scored  = array_values(array_map(fn (int $i) => $rows[$i], $indexes));

        return [$scored, $scored !== []];
    }
}
