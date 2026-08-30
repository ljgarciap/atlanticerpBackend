<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use Illuminate\Support\Collection;

/**
 * SCRUM-750 — resuelve el shape de `ventas_diseno.catalog_products` a partir de las tablas
 * `legacy.*` ya staged (por `ProductoStagingLoader`). Nunca toca `catalog_products` directamente
 * — eso es `CatalogProductLoader` ("Loader-B").
 *
 * Scopeado a la lista de `icg_codarticulo` de LA CORRIDA ACTUAL (no a todo el historial de
 * `legacy.productos`) — así el reporte de `--dry-run`/`legacy.sync_runs` refleja el extracto de
 * hoy, no arrastra artículos de corridas anteriores.
 */
final class ProductoMapper
{
    /**
     * IDTARIFAV=1 = PRECIO PUBLICO → `catalog_products.price_full`
     * (ver `App\Modules\Legacy\Support\PriceTypeMap`).
     */
    private const PUBLIC_IDTARIFAV = 1;

    /**
     * Corrección 2026-08-17 (hallazgo del cliente: "no sé qué son esos números, esa no es la
     * referencia") — `CODPROVEEDOR` por defecto para resolver `cost` desde `PRECIOSCOMPRA`.
     * Confirmado con data real de dev.atlanticerp.ai: es el proveedor dominante, concentra 7928/9677
     * (82%) de las filas de `legacy.producto_costos` — criterio de negocio confirmado por Luis
     * (proveedor/almacén interno por defecto de ICG). Mismo tratamiento que `PriceTypeMap`: es un
     * cruce estructural fijo del origen de datos, no un umbral/margen configurable, se mantiene
     * como constante de código.
     */
    private const DEFAULT_CODPROVEEDOR = 1001;

    /**
     * @param  list<int> $icgCodarticulos
     * @return list<MappedProducto>
     */
    public function buildPayloads(array $icgCodarticulos): array
    {
        if ($icgCodarticulos === []) {
            return [];
        }

        $productos = Producto::query()
            ->whereIn('icg_codarticulo', $icgCodarticulos)
            ->get()
            ->keyBy('icg_codarticulo');

        $preciosPublico = ProductoPrecio::query()
            ->whereIn('icg_codarticulo', $icgCodarticulos)
            ->where('idtarifav', self::PUBLIC_IDTARIFAV)
            ->get()
            ->keyBy('icg_codarticulo');

        $costosPorArticulo = ProductoCosto::query()
            ->whereIn('icg_codarticulo', $icgCodarticulos)
            ->get()
            ->groupBy('icg_codarticulo');

        return array_map(
            fn (int $codigo): MappedProducto => $this->mapOne(
                $codigo,
                $productos->get($codigo),
                $preciosPublico->get($codigo),
                $costosPorArticulo->get($codigo) ?? new Collection(),
            ),
            $icgCodarticulos,
        );
    }

    /** @param Collection<int, ProductoCosto> $costosDelArticulo */
    private function mapOne(
        int $codigo,
        ?Producto $producto,
        ?ProductoPrecio $precioPublico,
        Collection $costosDelArticulo,
    ): MappedProducto {
        // Corrección 2026-08-27 (SCRUM-799, reporte de Daniela Amaya) — reabre la decisión del
        // 2026-08-17 (commit 45fca4c): esa corrección dejó el código real de fabricante en
        // `factory_reference` y un ID sintético en `reference`, pero para el negocio ES esta
        // referencia (ARTICULOS.REFPROVEEDOR, ej. "42016B-WH") la que identifica públicamente el
        // producto — ICG no tiene ningún otro dato para "Referencia de fábrica" como concepto
        // aparte, así que ese campo queda vacío para productos migrados (se completa a mano si
        // hace falta). `reference` sigue siendo la llave de match de `CatalogProductLoader` para
        // update-vs-insert entre corridas — motivo por el que, a falta de REFPROVEEDOR real, cae
        // al ID sintético estable en vez de quedar sin valor (columna NOT NULL/unique).
        //
        // Los ~11,602 productos ya migrados con la asignación vieja se corrigen con
        // `ProductoReferenceSwapService`/`legacy:fix-productos-referencia` (comando aparte,
        // corrección de DATA, no de este mapper) — este cambio solo evita que una corrida nueva
        // vuelva a generar el cruce.
        $reference         = $producto !== null ? ($producto->ref_proveedor ?? "ICG-{$codigo}") : "ICG-{$codigo}";
        $factoryReference  = null;

        if ($producto === null) {
            return new MappedProducto(
                icgCodarticulo: $codigo,
                reference: $reference,
                factoryReference: $factoryReference,
                description: '',
                brand: null,
                priceFull: null,
                cost: null,
                excludedReason: 'no encontrado en legacy.productos — el Loader-A no llegó a stagearlo en esta corrida',
            );
        }

        if ($producto->descatalogado) {
            return new MappedProducto(
                icgCodarticulo: $codigo,
                reference: $reference,
                factoryReference: $factoryReference,
                description: $producto->descripcion,
                brand: $producto->marca_nombre,
                priceFull: null,
                cost: null,
                excludedReason: 'descatalogado en ICG',
            );
        }

        if ($precioPublico === null) {
            return new MappedProducto(
                icgCodarticulo: $codigo,
                reference: $reference,
                factoryReference: $factoryReference,
                description: $producto->descripcion,
                brand: $producto->marca_nombre,
                priceFull: null,
                cost: null,
                excludedReason: 'sin precio público (IDTARIFAV=1) en PRECIOSVENTA',
            );
        }

        return new MappedProducto(
            icgCodarticulo: $codigo,
            reference: $reference,
            factoryReference: $factoryReference,
            description: $producto->descripcion,
            brand: $producto->marca_nombre,
            priceFull: $precioPublico->precio_neto,
            cost: $this->resolveCost($costosDelArticulo),
            excludedReason: null,
        );
    }

    /**
     * Corrección 2026-08-17 — ya NO usa `ARTICULOS.REFPROVEEDOR` (era la ambigüedad original:
     * confirmado con data real que es la referencia alfanumérica del fabricante, ej. "42016B-WH",
     * no un `CODPROVEEDOR` numérico — por eso el join fallaba en el 92% de los casos y `cost`
     * quedaba `null` casi siempre sin que nada lo marcara como error). Ahora resuelve directo
     * contra `legacy.producto_costos` por el proveedor por defecto (`DEFAULT_CODPROVEEDOR`).
     *
     * @param Collection<int, ProductoCosto> $costosDelArticulo
     */
    private function resolveCost(Collection $costosDelArticulo): ?float
    {
        /** @var ProductoCosto|null $match */
        $match = $costosDelArticulo->first(
            fn (ProductoCosto $costo): bool => $costo->codproveedor === self::DEFAULT_CODPROVEEDOR,
        );

        return $match?->costo_neto;
    }
}
