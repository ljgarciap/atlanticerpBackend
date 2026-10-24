<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Services\ZonaLibreCatalogoService;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * SCRUM-990 — Bodega > Zona Libre: catálogo de Lafayette Lighting de SOLO CONSULTA. Un único endpoint de lectura
 * (`GET /bodega/zona-libre/productos`, permiso `bodega.read`); NO existe ninguna ruta de escritura sobre esta tabla
 * (un test de rutas lo afirma). El frontend consume el shape tal cual: lo fija `ZonaLibreCatalogoContractTest`.
 *
 * Parámetros (todos opcionales; un valor fuera del contrato responde 422, no se ignora en silencio):
 * - `search`           texto libre, mínimo 2 caracteres.
 * - `categoria`        coincidencia exacta con una de `meta.categorias`.
 * - `solo_disponibles` bool, apagado por defecto: los productos con stock 0 SE VEN (decisión de Luis, 2026-09-26).
 * - `sort` / `dir`     columna de la lista blanca (`ZonaLibreCatalogoService::SORTS`) y `asc|desc`.
 * - `page`, `per_page` 25 | 50 | 100 (sin "todas": ~25.800 filas nunca se cargan).
 */
final class ZonaLibreCatalogoController extends Controller
{
    public function __construct(private readonly ZonaLibreCatalogoService $catalogo) {}

    public function productos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'           => ['nullable', 'string', 'min:2', 'max:100'],
            'categoria'        => ['nullable', 'string', 'max:255'],
            // Un cliente JS/axios serializa un booleano como la cadena "true"/"false", que la regla `boolean` de Laravel RECHAZA
            // (solo acepta true/false/1/0/"1"/"0"): se acepta explícitamente cualquiera de las cuatro formas.
            'solo_disponibles' => ['nullable', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            'sort'             => ['nullable', 'string', Rule::in(array_keys(ZonaLibreCatalogoService::SORTS))],
            'dir'              => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page'             => ['nullable', 'integer', 'min:1', 'max:'.ZonaLibreCatalogoService::PAGE_MAX],
            'per_page'         => ['nullable', 'integer', Rule::in(ZonaLibreCatalogoService::PER_PAGE_OPTIONS)],
        ]);

        // `precio` (precio_6, SCRUM-1125) lo ve solo Compras (compras.read) y superadmin: sin ese permiso ni se consulta ni se serializa, y tampoco se puede ordenar por
        // él (el orden filtraría el precio). Permisos del JWT, como el resto de los endpoints de Compras.
        $permisos = (array) ($request->attributes->get('jwt')['permissions'] ?? []);
        $puedeVerPrecio = in_array('compras.read', $permisos, true) || in_array('superadmin.all', $permisos, true);
        if (! $puedeVerPrecio && ($validated['sort'] ?? null) === 'precio') {
            return response()->json([
                'message' => trans('validation.in', ['attribute' => 'sort']),
                'errors'  => ['sort' => [trans('validation.in', ['attribute' => 'sort'])]],
            ], 422);
        }

        $perPage = (int) ($validated['per_page'] ?? ZonaLibreCatalogoService::PER_PAGE_OPTIONS[0]);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = $this->catalogo->listar([
            'search'           => $validated['search'] ?? null,
            'categoria'        => $validated['categoria'] ?? null,
            'solo_disponibles' => $request->boolean('solo_disponibles'),
            'sort'             => $validated['sort'] ?? ZonaLibreCatalogoService::DEFAULT_SORT,
            'dir'              => $validated['dir'] ?? 'asc',
        ], $perPage, $page, $puedeVerPrecio);

        $resumen = $this->catalogo->resumen();

        return response()->json([
            'data' => array_map(fn (\stdClass $row): array => $this->catalogo->formatRow($row, $puedeVerPrecio), $paginator->items()),
            'meta' => Pagination::meta($paginator) + [
                'categorias'            => $resumen['categorias'],
                'total_catalogo'        => $resumen['total_catalogo'],
                'ultima_sincronizacion' => $this->catalogo->ultimaSincronizacion(),
                'puede_ver_precio'      => $puedeVerPrecio,
            ],
        ]);
    }
}
