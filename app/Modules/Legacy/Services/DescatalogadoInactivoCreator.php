<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1136 F5, RF3 + RF4 — crea en `ventas_diseno.catalog_products` los artículos descatalogados de ICG con stock como productos INACTIVOS (`is_active = false`),
 * para poder asociarles stock por bodega sin que aparezcan en selectores, listados ni cotizaciones del catálogo activo.
 *
 * Reutiliza el mapeo de producto existente (`ProductoMapper`, con su vía explícita `paraDescatalogadoInactivo`: costo, precio público, marca) — el flujo de activos
 * sigue excluyendo a los descatalogados. NO escribe stock: ni `product_warehouse_stock`, ni kardex, ni `catalog_products.stock_quantity` (RF6, esa es la fase de
 * mapeo de stock). `legacy.productos.stock_al4_aplicado` tampoco se toca: queda nulo ("nunca cargado") y el sync de delta ya ignora a los descatalogados.
 *
 * Clasificación de cada descatalogado con stock (excluyentes, en este orden):
 *  - `con_equivalente`: comparte referencia con un activo → NO se crea (su `catalog_product_id` es el del activo, ver `DescatalogadoEquivalenciaService`).
 *  - `revision_manual`: equivalencia ambigua o en conflicto → NO se crea.
 *  - `sin_referencia` (RF4): sin REFPROVEEDOR → NO se crea, se lista para revisión manual.
 *  - `ya_ligados`: ya tiene `catalog_product_id` → NO se crea (idempotencia: la segunda corrida crea 0).
 *  - `colisiones`: su referencia ya existe en `catalog_products` (recortada, sin distinguir mayúsculas) o se repite dentro del lote → NO se crea: nunca se duplica
 *    `reference` ni se pisa un producto ajeno; se reporta para decisión manual.
 *  - `crear`: el resto.
 * Cada producto se crea en su propia transacción (el alta del catálogo y el vínculo en `legacy.productos` van juntos o no van). La creación del `CatalogProduct`
 * deja su rastro en `audit_logs` vía `LogsAuditTrail`.
 */
final class DescatalogadoInactivoCreator
{
    public function __construct(
        private readonly DescatalogadoEquivalenciaService $equivalencias,
        private readonly ProductoMapper $mapper,
    ) {
    }

    /**
     * Solo lecturas. `vinculos` son las equivalencias calculadas (todavía sin escribir) para que un dry-run informe igual que lo haría el apply.
     *
     * @param  list<int> $codigos
     * @return array{
     *   total: int,
     *   con_equivalente: list<int>,
     *   revision_manual: list<int>,
     *   sin_referencia: list<array{icg_codarticulo: int, descripcion: string}>,
     *   ya_ligados: list<int>,
     *   colisiones: list<array{icg_codarticulo: int, reference: string, motivo: string}>,
     *   crear: list<int>,
     *   crear_sin_precio_publico: list<int>,
     *   vinculos: array<int, array{equivalente: int, catalog_product_id: int|null}>,
     *   pares: list<array{icg_codarticulo: int, referencia: string, equivalente: int, catalog_product_id: int|null}>,
     * }
     */
    public function planear(array $codigos): array
    {
        $eq        = $this->equivalencias->calcular($codigos);
        $productos = Producto::query()->where('descatalogado', true)->whereIn('icg_codarticulo', $codigos)->orderBy('icg_codarticulo')->get();

        $conEquivalente = $revisionManual = $yaLigados = $crear = [];
        $sinReferencia  = $colisiones = [];
        $vistas         = [];

        foreach ($productos as $p) {
            $codigo = $p->icg_codarticulo;
            $ref    = DescatalogadoEquivalenciaService::normalizar($p->ref_proveedor);

            if ($p->equivalente_icg_codarticulo !== null || isset($eq['vinculos'][$codigo])) {
                $conEquivalente[] = $codigo;
            } elseif (in_array($codigo, $eq['ambiguos'], true) || in_array($codigo, $eq['conflictos'], true)) {
                $revisionManual[] = $codigo;
            } elseif ($ref === null) {
                $sinReferencia[] = ['icg_codarticulo' => $codigo, 'descripcion' => $p->descripcion];
            } elseif ($p->catalog_product_id !== null) {
                $yaLigados[] = $codigo;
            } elseif (isset($vistas[$ref])) {
                $colisiones[] = ['icg_codarticulo' => $codigo, 'reference' => (string) $p->ref_proveedor, 'motivo' => "referencia repetida con el artículo {$vistas[$ref]} del mismo lote"];
            } elseif ($this->referenciaExisteEnCatalogo($ref)) {
                $colisiones[] = ['icg_codarticulo' => $codigo, 'reference' => (string) $p->ref_proveedor, 'motivo' => 'la referencia ya existe en catalog_products (no se duplica ni se pisa)'];
            } else {
                $vistas[$ref] = $codigo;
                $crear[]      = $codigo;
            }
        }

        $conPrecio = $crear === [] ? [] : ProductoPrecio::query()->whereIn('icg_codarticulo', $crear)->where('idtarifav', 1)->pluck('icg_codarticulo')->map(fn ($c): int => (int) $c)->all();

        return [
            'total'                    => $productos->count(),
            'con_equivalente'          => $conEquivalente,
            'revision_manual'          => $revisionManual,
            'sin_referencia'           => $sinReferencia,
            'ya_ligados'               => $yaLigados,
            'colisiones'               => $colisiones,
            'crear'                    => $crear,
            'crear_sin_precio_publico' => array_values(array_diff($crear, $conPrecio)),
            'vinculos'                 => $eq['vinculos'],
            'pares'                    => $eq['pares'],
        ];
    }

    /**
     * Crea los productos inactivos de `$codigos` (los del plan). Cada uno en su transacción; un error de uno no detiene al resto.
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
        if ($producto === null || ! $producto->descatalogado) {
            return 'ya no es un descatalogado en staging';
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
            // Sin precio público en ICG: 0.0 (el mismo `(float) null` que usa el loader de activos); el producto es inactivo y no se vende.
            'price_full'        => (float) $mapped->priceFull,
            'cost'              => $mapped->cost,
            'is_active'         => false,
            // RF6: sin stock en esta fase (ni stock_quantity, ni bodega, ni kardex).
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
}
