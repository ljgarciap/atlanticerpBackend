<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\ProductoCosto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve el `cost` de un artículo desde `legacy.producto_costos` (1 fila por artículo+proveedor). Fuente única de la regla: la usan
 * `ProductoMapper` (sync) y `legacy:backfill-costo-proveedor` (productos ya migrados).
 *
 * Regla (spec aprobada por Luis 2026-10-14, caso A691-451471527K — su único costo estaba con MARSET, proveedor 70):
 *  1. Si existe costo del proveedor por defecto (1001) → ese (comportamiento histórico, sin cambios).
 *  2. Si no, y el artículo tiene UN solo proveedor → el costo de ese proveedor.
 *  3. Si no, y tiene varios → el proveedor de la compra más reciente (`albaranes_compra.fecha` de las líneas de ese artículo, solo entre los
 *     proveedores que tienen costo). Sin compras, o empate en la fecha más reciente → null: se deja vacío para que Compras lo revise, nunca se adivina.
 *     El valor es el `costo_neto` de ese proveedor en `producto_costos`, no el `precio` del albarán (misma fuente y semántica que la regla 1).
 */
final class ProductoCostoResolver
{
    /**
     * Corrección 2026-08-17 — `CODPROVEEDOR` por defecto para resolver `cost` desde `PRECIOSCOMPRA`. Confirmado con data real: es el proveedor
     * dominante (82% de las filas de `legacy.producto_costos`), criterio de negocio de Luis. Cruce estructural fijo del origen de datos, no un
     * umbral/margen configurable: constante de código (mismo tratamiento que `PriceTypeMap`).
     */
    public const DEFAULT_CODPROVEEDOR = 1001;

    /**
     * @param Collection<int, ProductoCosto> $costos Todas las filas de `producto_costos` del artículo.
     * @param array<int, string> $ultimaCompraPorProveedor codproveedor => fecha (ISO) de su compra más reciente de este artículo.
     */
    public function resolve(Collection $costos, array $ultimaCompraPorProveedor = []): ?float
    {
        $porDefecto = $costos->first(fn (ProductoCosto $c): bool => (int) $c->codproveedor === self::DEFAULT_CODPROVEEDOR);
        if ($porDefecto !== null) {
            return (float) $porDefecto->costo_neto;
        }
        if ($costos->count() === 1) {
            return (float) $costos->first()?->costo_neto;
        }
        if ($costos->isEmpty()) {
            return null;
        }

        $conCompra = $costos
            ->filter(fn (ProductoCosto $c): bool => isset($ultimaCompraPorProveedor[(int) $c->codproveedor]))
            ->sortByDesc(fn (ProductoCosto $c): string => $ultimaCompraPorProveedor[(int) $c->codproveedor])
            ->values();
        $mejor = $conCompra->first();
        if ($mejor === null) {
            return null;
        }
        $segundo = $conCompra->get(1);
        if ($segundo !== null && $ultimaCompraPorProveedor[(int) $segundo->codproveedor] === $ultimaCompraPorProveedor[(int) $mejor->codproveedor]) {
            return null;
        }

        return (float) $mejor->costo_neto;
    }

    /**
     * Fecha de la última compra de cada artículo por proveedor, para resolver el caso de varios proveedores.
     *
     * @param array<int, int> $icgCodarticulos
     * @return array<int, array<int, string>> codarticulo => [codproveedor => fecha]
     */
    public function ultimasComprasPorProveedor(array $icgCodarticulos): array
    {
        if ($icgCodarticulos === []) {
            return [];
        }

        $items = (new AlbaranCompraItem())->getTable();
        $cabeceras = (new AlbaranCompra())->getTable();
        $resultado = [];
        foreach (array_chunk(array_values($icgCodarticulos), 1000) as $lote) {
            $filas = DB::table("{$items} as i")
                ->join("{$cabeceras} as a", 'a.id', '=', 'i.legacy_albaran_compra_id')
                ->whereIn('i.icg_codarticulo', $lote)
                ->whereNotNull('a.icg_codproveedor')
                ->whereNotNull('a.fecha')
                ->groupBy('i.icg_codarticulo', 'a.icg_codproveedor')
                ->selectRaw('i.icg_codarticulo, a.icg_codproveedor, MAX(a.fecha) AS ultima')
                ->get();
            foreach ($filas as $f) {
                $resultado[(int) $f->icg_codarticulo][(int) $f->icg_codproveedor] = (string) $f->ultima;
            }
        }

        return $resultado;
    }
}
