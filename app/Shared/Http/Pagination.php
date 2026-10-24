<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Política global de Luis (daily sprint2, 2026-07-14): toda vista de tabla pagina desde el
 * backend, con selector y sorting configurable — ver memory/feedback_pagination_sorting_policy.md.
 * Un solo lugar para parsear los query params y construir la respuesta, para que cada controller
 * no reinvente el contrato.
 *
 * SCRUM-754 (2026-08-18) — estandarización: default pasó de 5 a 20 registros, y se quitó la
 * opción 5 del selector (10/20/50/100/todas), a pedido explícito del cliente. Ver
 * docs/changelog.md para el detalle del batch.
 */
final class Pagination
{
    private const ALLOWED_PER_PAGE = [10, 20, 50, 100];
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_ALL = 1000; // techo defensivo para "todos" — evita traer un dataset infinito.

    /**
     * SCRUM-992 (Pre-QA H6) — techo de `page`. Con `per_page` máximo 1000 el OFFSET más grande
     * posible es ~1e9, siempre dentro de un entero. Sin techo, `page=9223372036854775808` se
     * convertía en PHP_INT_MAX y `(page - 1) * per_page` desbordaba a float: devolvía las filas de
     * la página 1 con un `current_page` absurdo. Una página válida (<= techo) no cambia; una fuera
     * de rango sigue devolviendo `data: []` con el `current_page` pedido, como siempre.
     */
    private const MAX_PAGE = 1_000_000;

    /**
     * @param list<string> $allowedSorts
     * @return array{per_page: int, page: int, sort_by: string, sort_dir: string}
     */
    public static function params(Request $request, array $allowedSorts, string $defaultSort, string $defaultDir = 'asc'): array
    {
        // H8 — un parámetro no escalar (`per_page[]=1`) se trata como ausente, ver QueryParams.
        $perPageRaw = QueryParams::string($request, 'per_page', (string) self::DEFAULT_PER_PAGE);
        $perPage = $perPageRaw === 'all'
            ? self::MAX_ALL
            : (in_array((int) $perPageRaw, self::ALLOWED_PER_PAGE, true) ? (int) $perPageRaw : self::DEFAULT_PER_PAGE);

        $pageRaw = $request->query('page', 1);
        $page = is_scalar($pageRaw) ? min(self::MAX_PAGE, max(1, (int) $pageRaw)) : 1;

        $sortByRaw = $request->query('sort_by');
        $sortBy = is_string($sortByRaw) && in_array($sortByRaw, $allowedSorts, true) ? $sortByRaw : $defaultSort;

        $sortDirRaw = strtolower(QueryParams::string($request, 'sort_dir', $defaultDir));
        $sortDir = $sortDirRaw === 'desc' ? 'desc' : 'asc';

        return ['per_page' => $perPage, 'page' => $page, 'sort_by' => $sortBy, 'sort_dir' => $sortDir];
    }

    /**
     * Pagina un array ya filtrado/ordenado en PHP (necesario cuando el listado usa
     * FuzzySearchService, que trabaja sobre arrays, no sobre queries — ver Provider/
     * CatalogProduct). Mismo shape de resultado que Model::paginate().
     *
     * @template T
     * @param list<T> $items
     * @return LengthAwarePaginator<int, T>
     */
    public static function ofArray(array $items, int $perPage, int $page): LengthAwarePaginator
    {
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator($slice, $total, $perPage, $page);
    }

    /**
     * @param LengthAwarePaginator<int, mixed> $paginator
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ];
    }
}
