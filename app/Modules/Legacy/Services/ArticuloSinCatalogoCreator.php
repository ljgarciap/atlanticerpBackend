<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1136 F6 — crea en `ventas_diseno.catalog_products` como INACTIVOS los artículos ACTIVOS de ICG (`legacy.productos.descatalogado = false`) que tienen stock <> 0
 * en un almacén cuyo stock se va a cargar y NO resuelven a ningún catálogo (hallazgo F6a: 42 artículos de B2 dados de alta en ICG después de que el sync de productos
 * dejó de crear catálogo). Decisión de Luis 2026-10-15: inactivos, mismo patrón que F5 (`DescatalogadoInactivoCreator`), para poder asociarles stock sin que aparezcan en
 * selectores ni cotizaciones.
 *
 * Reutiliza `ProductoMapper::buildPayloads($codigos, true)` (la vía "para inactivo" mapea costo, precio público y marca sin excluir por descatalogado/sin precio). NO escribe
 * stock: la carga la hace `StockBodegaLoader`. Los descatalogados sin catálogo NO son de este creador (son de F5: `legacy:crear-descatalogados-inactivos`): se listan aparte.
 *
 * Clasificación (excluyentes): `crear`; `sin_referencia` (revisión manual); `colisiones` (la referencia ya existe en el catálogo o se repite en el lote: nunca se duplica ni se pisa);
 * `descatalogados` (de F5); `sin_staging` (el artículo ni siquiera está en `legacy.productos`).
 */
final class ArticuloSinCatalogoCreator
{
    public function __construct(private readonly ProductoMapper $mapper)
    {
    }

    /**
     * Solo lecturas.
     *
     * @param  list<string> $almacenes códigos ICG de los almacenes cuyo stock se cargará
     * @return array{
     *   crear: list<int>, sin_referencia: list<array{icg_codarticulo: int, descripcion: string}>,
     *   colisiones: list<array{icg_codarticulo: int, reference: string, motivo: string}>,
     *   descatalogados: list<int>, sin_staging: list<int>, excluidos: list<int>, crear_sin_precio_publico: list<int>
     * }
     */
    public function planear(array $almacenes): array
    {
        $vacio = ['crear' => [], 'sin_referencia' => [], 'colisiones' => [], 'descatalogados' => [], 'sin_staging' => [], 'excluidos' => [], 'crear_sin_precio_publico' => []];
        if ($almacenes === []) {
            return $vacio;
        }

        $productos = $this->tabla('productos');
        $stock = $this->tabla('stock_almacen');
        $marcas = implode(',', array_fill(0, count($almacenes), '?'));
        $excluidosCfg = array_map('intval', (array) config('legacy.carga_stock_excluir_codarticulos', []));

        $codigos = array_map(
            static fn (\stdClass $r): int => (int) $r->codarticulo,
            DB::select(
                "SELECT DISTINCT s.codarticulo FROM {$stock} s
                 LEFT JOIN {$productos} p ON p.icg_codarticulo = s.codarticulo
                 LEFT JOIN {$productos} e ON e.icg_codarticulo = p.equivalente_icg_codarticulo
                 WHERE s.codalmacen IN ({$marcas}) AND s.stock IS NOT NULL AND s.stock <> 0
                   AND (p.icg_codarticulo IS NULL OR COALESCE(p.catalog_product_id, e.catalog_product_id) IS NULL)
                 ORDER BY s.codarticulo",
                $almacenes,
            ),
        );

        $plan = $vacio;
        $vistas = [];
        $enStaging = Producto::query()->whereIn('icg_codarticulo', $codigos)->get()->keyBy('icg_codarticulo');
        foreach ($codigos as $codigo) {
            $p = $enStaging->get($codigo);
            if (in_array($codigo, $excluidosCfg, true)) {
                $plan['excluidos'][] = $codigo;
            } elseif ($p === null) {
                $plan['sin_staging'][] = $codigo;
            } elseif ($p->descatalogado) {
                $plan['descatalogados'][] = $codigo;
            } else {
                $ref = DescatalogadoEquivalenciaService::normalizar($p->ref_proveedor);
                if ($ref === null) {
                    $plan['sin_referencia'][] = ['icg_codarticulo' => $codigo, 'descripcion' => (string) $p->descripcion];
                } elseif (isset($vistas[$ref])) {
                    $plan['colisiones'][] = ['icg_codarticulo' => $codigo, 'reference' => (string) $p->ref_proveedor, 'motivo' => "referencia repetida con el artículo {$vistas[$ref]} del mismo lote"];
                } elseif ($this->referenciaExisteEnCatalogo($ref)) {
                    $plan['colisiones'][] = ['icg_codarticulo' => $codigo, 'reference' => (string) $p->ref_proveedor, 'motivo' => 'la referencia ya existe en catalog_products (no se duplica ni se pisa)'];
                } else {
                    $vistas[$ref] = $codigo;
                    $plan['crear'][] = $codigo;
                }
            }
        }

        if ($plan['crear'] !== []) {
            $conPrecio = DB::table($this->tabla('producto_precios'))->whereIn('icg_codarticulo', $plan['crear'])->where('idtarifav', 1)->pluck('icg_codarticulo')->map(fn ($c): int => (int) $c)->all();
            $plan['crear_sin_precio_publico'] = array_values(array_diff($plan['crear'], $conPrecio));
        }

        return $plan;
    }

    /**
     * Cada producto en su propia transacción; un error de uno no detiene al resto.
     *
     * @param  list<int> $codigos
     * @return array{creados: list<array{icg_codarticulo: int, catalog_product_id: int, reference: string}>, omitidos: list<array{icg_codarticulo: int, motivo: string}>, errores: list<array{icg_codarticulo: int, motivo: string}>}
     */
    public function crear(array $codigos): array
    {
        $creados = $omitidos = $errores = [];
        foreach ($codigos as $codigo) {
            try {
                $r = DB::transaction(fn (): array|string => $this->crearUno($codigo));
            } catch (Throwable $e) {
                $errores[] = ['icg_codarticulo' => $codigo, 'motivo' => $e::class . ' al crear el producto inactivo'];
                continue;
            }
            if (is_string($r)) {
                $omitidos[] = ['icg_codarticulo' => $codigo, 'motivo' => $r];
            } else {
                $creados[] = $r;
            }
        }

        return ['creados' => $creados, 'omitidos' => $omitidos, 'errores' => $errores];
    }

    /** @return array{icg_codarticulo: int, catalog_product_id: int, reference: string}|string motivo de omisión */
    private function crearUno(int $codigo): array|string
    {
        // Bloqueo de la fila de staging: dos corridas simultáneas no crean el mismo producto dos veces.
        $producto = Producto::query()->where('icg_codarticulo', $codigo)->lockForUpdate()->first();
        if ($producto === null || $producto->descatalogado) {
            return 'ya no es un activo en staging';
        }
        if ($producto->catalog_product_id !== null || $producto->equivalente_icg_codarticulo !== null) {
            return 'ya estaba ligado a un producto de catálogo';
        }
        $ref = DescatalogadoEquivalenciaService::normalizar($producto->ref_proveedor);
        if ($ref === null) {
            return 'sin referencia';
        }
        if ($this->referenciaExisteEnCatalogo($ref)) {
            return 'la referencia ya existe en catalog_products';
        }

        $mapped = $this->mapper->buildPayloads([$codigo], true)[0] ?? null;
        if ($mapped === null) {
            return 'sin mapeo';
        }
        if ($mapped->isExcluded()) {
            return (string) $mapped->excludedReason;
        }

        $catalogProduct = CatalogProduct::query()->create([
            'reference'         => $mapped->reference,
            'name'              => $mapped->description,
            'brand'             => $mapped->brand,
            'factory_reference' => $mapped->factoryReference,
            'price_full'        => (float) $mapped->priceFull,
            'cost'              => $mapped->cost,
            'is_active'         => false,
            // Sin stock aquí: el stock por bodega (y el agregado) lo escribe `StockBodegaLoader`.
            'stock_quantity'    => null,
        ]);

        $producto->catalog_product_id = $catalogProduct->id;
        $producto->save();

        return ['icg_codarticulo' => $codigo, 'catalog_product_id' => (int) $catalogProduct->id, 'reference' => $mapped->reference];
    }

    private function referenciaExisteEnCatalogo(string $referenciaNormalizada): bool
    {
        return CatalogProduct::query()->whereRaw('lower(trim(reference)) = ?', [$referenciaNormalizada])->exists();
    }

    private function tabla(string $nombre): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? $nombre : "{$tenant->slug}_legacy.{$nombre}";
    }
}
