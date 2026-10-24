<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\ProductoCosto;
use Illuminate\Support\Collection;

/**
 * Resuelve los proveedores de un artículo de ICG para vincularlos en AtlanticERP (`catalog_products.provider_id` principal + `catalog_product_providers`),
 * a partir de `legacy.producto_costos` (con quién tiene costo el artículo) y del enlace `legacy.proveedores.provider_id` → `compras.providers`.
 *
 * Spec aprobada por Luis 2026-10-14 (`docs/specs/icg-vinculacion-productos-proveedores.md`):
 *  - Se vinculan TODOS los proveedores del artículo que ya estén enlazados a `compras.providers`; los que no tienen enlace se omiten (no se crean).
 *  - Principal: el proveedor por defecto (1001) si el artículo tiene costo con él; si no, el único; si hay varios, el de la compra más reciente
 *    (misma regla que `ProductoCostoResolver`, para que costo y proveedor principal no se contradigan). Con empate en la fecha más reciente, o sin
 *    compras, el principal es el de menor código de ICG (determinista) y el resultado se marca `empate` para que Compras lo revise.
 */
final class ProductoProveedorResolver
{
    /**
     * @param Collection<int, ProductoCosto> $costos Todas las filas de `producto_costos` del artículo.
     * @param array<int, int> $providerPorCodproveedor codproveedor de ICG => `compras.providers.id` (solo los enlazados).
     * @param array<int, string> $ultimaCompraPorProveedor codproveedor => fecha (ISO) de su compra más reciente de este artículo.
     * @return array{principal: int, proveedores: list<int>, empate: bool, sin_enlace: int}|null null si ningún proveedor del artículo está enlazado.
     */
    public function resolve(Collection $costos, array $providerPorCodproveedor, array $ultimaCompraPorProveedor = []): ?array
    {
        $candidatos = $costos
            ->filter(fn (ProductoCosto $c): bool => isset($providerPorCodproveedor[(int) $c->codproveedor]))
            ->sortBy(fn (ProductoCosto $c): int => (int) $c->codproveedor)
            ->values();
        if ($candidatos->isEmpty()) {
            return null;
        }

        $proveedores = array_values($candidatos
            ->map(fn (ProductoCosto $c): int => $providerPorCodproveedor[(int) $c->codproveedor])
            ->unique()
            ->sort()
            ->all());
        $sinEnlace = $costos->count() - $candidatos->count();

        $porDefecto = $candidatos->first(fn (ProductoCosto $c): bool => (int) $c->codproveedor === ProductoCostoResolver::DEFAULT_CODPROVEEDOR);
        if ($porDefecto !== null) {
            return ['principal' => $providerPorCodproveedor[(int) $porDefecto->codproveedor], 'proveedores' => $proveedores, 'empate' => false, 'sin_enlace' => $sinEnlace];
        }
        if ($candidatos->count() === 1) {
            return ['principal' => $proveedores[0], 'proveedores' => $proveedores, 'empate' => false, 'sin_enlace' => $sinEnlace];
        }

        $conCompra = $candidatos->filter(fn (ProductoCosto $c): bool => isset($ultimaCompraPorProveedor[(int) $c->codproveedor]));
        if ($conCompra->isEmpty()) {
            $grupo = $candidatos;
        } else {
            $masReciente = $conCompra->map(fn (ProductoCosto $c): string => $ultimaCompraPorProveedor[(int) $c->codproveedor])->max();
            $grupo = $conCompra->filter(fn (ProductoCosto $c): bool => $ultimaCompraPorProveedor[(int) $c->codproveedor] === $masReciente);
        }
        // `$candidatos` ya viene ordenado por codproveedor: el primero del grupo es el de menor código.
        $elegido = $grupo->values()->first();
        if ($elegido === null) {
            return null;
        }
        $empate = $grupo->map(fn (ProductoCosto $c): int => $providerPorCodproveedor[(int) $c->codproveedor])->unique()->count() > 1;

        return [
            'principal'   => $providerPorCodproveedor[(int) $elegido->codproveedor],
            'proveedores' => $proveedores,
            'empate'      => $empate,
            'sin_enlace'  => $sinEnlace,
        ];
    }
}
