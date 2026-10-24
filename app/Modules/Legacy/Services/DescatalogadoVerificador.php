<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F5, RF5 — resumen de solo lectura de los artículos descatalogados de ICG con stock. "Con stock" = existe en `legacy.stock_almacen` (copia de F1 de
 * STOCKS) alguna fila con `stock <> 0` para el artículo, que es la misma definición que usa la extracción (`STOCKS.STOCK <> 0`).
 *
 * Reglas de clasificación (excluyentes, en este orden) sobre los descatalogados con stock presentes en `legacy.productos`:
 *  - `con_equivalente`: tiene `equivalente_icg_codarticulo` (apunta al producto del activo).
 *  - `sin_referencia`: sin `ref_proveedor` (revisión manual, nunca se crea en catálogo).
 *  - `creados_inactivos`: ya ligado a un `catalog_products` con `is_active = false`.
 *  - `ligados_otros`: ligado a un catálogo ACTIVO sin ser equivalente (p. ej. un activo que luego pasó a descatalogado).
 *  - `pendientes`: con referencia, sin vínculo ni catálogo (lo que `legacy:crear-descatalogados-inactivos` crearía).
 * `equivalentes_sin_catalogo`: equivalentes cuyo activo todavía no tiene catálogo. `sin_resolver`: artículos con referencia útil que NO resuelven a ningún `catalog_products`
 * (pendientes + equivalentes sin catálogo): incumple el criterio 7 de la spec; los sin referencia no cuentan (van a revisión manual).
 * `sin_staging` = artículos con stock <> 0 en `legacy.stock_almacen` que no están en `legacy.productos` (debería ser 0 tras la carga; incluye servicios activos
 * con stock que nunca se stagearon, que no son descatalogados).
 */
final class DescatalogadoVerificador
{
    /**
     * @return array{
     *   esperados: int|null,
     *   staged: int,
     *   con_equivalente: int,
     *   sin_referencia: int,
     *   creados_inactivos: int,
     *   ligados_otros: int,
     *   pendientes: int,
     *   sin_staging: int,
     *   sin_referencia_codigos: list<array{icg_codarticulo: int, descripcion: string}>,
     *   equivalentes_sin_catalogo: list<int>,
     *   sin_resolver: list<int>,
     *   stock_por_almacen: list<array{codalmacen: string, articulos: int, stock: float}>,
     * }
     */
    public function resumen(?int $esperados = null): array
    {
        $stock = self::tablaStockAlmacen();
        $conStock = "EXISTS (SELECT 1 FROM {$stock} sa WHERE sa.codarticulo = p.icg_codarticulo AND sa.stock <> 0)";

        $descatalogados = Producto::query()
            ->from((new Producto())->getTable() . ' as p')
            ->where('p.descatalogado', true)
            ->whereRaw($conStock);

        $filas = (clone $descatalogados)->get(['p.icg_codarticulo', 'p.descripcion', 'p.ref_proveedor', 'p.catalog_product_id', 'p.equivalente_icg_codarticulo']);

        $inactivos = CatalogProduct::query()
            ->whereIn('id', $filas->pluck('catalog_product_id')->filter()->unique()->all())
            ->where('is_active', false)
            ->pluck('id')
            ->flip();

        // Catálogo de cada activo equivalente: la resolución sigue el vínculo (el equivalente no guarda catálogo propio, ver DescatalogadoEquivalenciaService).
        $catalogoDeActivos = Producto::query()
            ->whereIn('icg_codarticulo', $filas->pluck('equivalente_icg_codarticulo')->filter()->unique()->all())
            ->pluck('catalog_product_id', 'icg_codarticulo');

        $equivalentesSinCatalogo = $sinResolver = [];
        $c = ['con_equivalente' => 0, 'sin_referencia' => 0, 'creados_inactivos' => 0, 'ligados_otros' => 0, 'pendientes' => 0];
        $sinReferencia = [];
        foreach ($filas as $f) {
            if ($f->equivalente_icg_codarticulo !== null) {
                $c['con_equivalente']++;
                if ($catalogoDeActivos->get($f->equivalente_icg_codarticulo) === null) {
                    $equivalentesSinCatalogo[] = $f->icg_codarticulo;
                    $sinResolver[]             = $f->icg_codarticulo;
                }
            } elseif (DescatalogadoEquivalenciaService::normalizar($f->ref_proveedor) === null) {
                $c['sin_referencia']++;
                $sinReferencia[] = ['icg_codarticulo' => $f->icg_codarticulo, 'descripcion' => $f->descripcion];
            } elseif ($f->catalog_product_id !== null && $inactivos->has($f->catalog_product_id)) {
                $c['creados_inactivos']++;
            } elseif ($f->catalog_product_id !== null) {
                $c['ligados_otros']++;
            } else {
                $c['pendientes']++;
                $sinResolver[] = $f->icg_codarticulo;
            }
        }

        $porAlmacen = DB::select(
            "SELECT sa.codalmacen, COUNT(DISTINCT sa.codarticulo) AS articulos, COALESCE(SUM(sa.stock), 0) AS stock
             FROM {$stock} sa
             WHERE sa.stock <> 0 AND sa.codarticulo IN (SELECT p.icg_codarticulo FROM " . (new Producto())->getTable() . " p WHERE p.descatalogado = true)
             GROUP BY sa.codalmacen ORDER BY sa.codalmacen",
        );

        $sinStaging = DB::selectOne(
            "SELECT COUNT(DISTINCT sa.codarticulo) AS n FROM {$stock} sa
             WHERE sa.stock <> 0 AND NOT EXISTS (SELECT 1 FROM " . (new Producto())->getTable() . ' p WHERE p.icg_codarticulo = sa.codarticulo)',
        );

        return [
            'esperados'              => $esperados,
            'staged'                 => $filas->count(),
            ...$c,
            'sin_staging'            => (int) $sinStaging->n,
            'sin_referencia_codigos' => $sinReferencia,
            'equivalentes_sin_catalogo' => $equivalentesSinCatalogo,
            'sin_resolver'           => $sinResolver,
            // PDO de Postgres devuelve double como texto: se castea explícitamente.
            'stock_por_almacen'      => array_values(array_map(
                static fn (\stdClass $r): array => ['codalmacen' => (string) $r->codalmacen, 'articulos' => (int) $r->articulos, 'stock' => (float) $r->stock],
                $porAlmacen,
            )),
        ];
    }

    /** Diferencia máxima tolerada al comparar sumas de float (error de representación, no una tolerancia de negocio). */
    public const TOLERANCIA_FLOAT = 0.000001;

    /**
     * Compara la suma de stock por almacén de staging contra la de ICG.
     *
     * @param  list<array{codalmacen: string, articulos: int, stock: float}> $staging
     * @param  array<string, array{articulos: int, stock: float}>            $icg
     * @return list<array{codalmacen: string, staging: float, icg: float, diferencia: float, articulos_staging: int, articulos_icg: int}>
     */
    public static function compararStock(array $staging, array $icg): array
    {
        $local = [];
        foreach ($staging as $f) {
            $local[$f['codalmacen']] = $f;
        }
        $almacenes = array_map('strval', array_unique([...array_keys($local), ...array_keys($icg)]));
        sort($almacenes);

        $out = [];
        foreach ($almacenes as $a) {
            $s     = $local[$a]['stock'] ?? 0.0;
            $i     = $icg[$a]['stock'] ?? 0.0;
            $out[] = [
                'codalmacen' => $a, 'staging' => $s, 'icg' => $i, 'diferencia' => $s - $i,
                'articulos_staging' => $local[$a]['articulos'] ?? 0, 'articulos_icg' => $icg[$a]['articulos'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Códigos de los descatalogados con stock presentes en staging (alcance de los comandos de creación).
     *
     * @return list<int>
     */
    public function codigosConStock(): array
    {
        $stock = self::tablaStockAlmacen();

        return array_values(array_map('intval', Producto::query()
            ->from((new Producto())->getTable() . ' as p')
            ->where('p.descatalogado', true)
            ->whereRaw("EXISTS (SELECT 1 FROM {$stock} sa WHERE sa.codarticulo = p.icg_codarticulo AND sa.stock <> 0)")
            ->orderBy('p.icg_codarticulo')
            ->pluck('p.icg_codarticulo')
            ->all()));
    }

    private static function tablaStockAlmacen(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'stock_almacen';
        }

        return "{$tenant->slug}_legacy.stock_almacen";
    }
}
