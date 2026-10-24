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

    public function __construct(private readonly ProductoCostoResolver $costoResolver = new ProductoCostoResolver) {}

    /**
     * `$paraDescatalogadoInactivo` (SCRUM-1136 F5): vía explícita para crear un producto INACTIVO desde un descatalogado de ICG con stock. Con `false` (default,
     * todo el flujo de activos) el comportamiento es el de siempre: un descatalogado se excluye ('descatalogado en ICG') y un artículo sin precio público también.
     * Con `true` el descatalogado NO se excluye, no hay stock inicial (RF6: el stock por bodega es otra fase) y la falta de precio público no excluye (queda
     * `priceFull = null`; el llamador decide qué hacer).
     *
     * @param  list<int>  $icgCodarticulos
     * @return list<MappedProducto>
     */
    public function buildPayloads(array $icgCodarticulos, bool $paraDescatalogadoInactivo = false): array
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

        // Solo los artículos con VARIOS proveedores y sin el por defecto necesitan la última compra para desempatar.
        $necesitanCompras = $costosPorArticulo
            ->filter(fn (Collection $costos): bool => $costos->count() > 1
                && $costos->doesntContain(fn (ProductoCosto $c): bool => (int) $c->codproveedor === ProductoCostoResolver::DEFAULT_CODPROVEEDOR))
            ->keys()
            ->map(fn ($k): int => (int) $k)
            ->values()
            ->all();
        $compras = $this->costoResolver->ultimasComprasPorProveedor($necesitanCompras);

        return array_map(
            fn (int $codigo): MappedProducto => $this->mapOne(
                $codigo,
                $productos->get($codigo),
                $preciosPublico->get($codigo),
                $costosPorArticulo->get($codigo) ?? new Collection,
                $compras[$codigo] ?? [],
                $paraDescatalogadoInactivo,
            ),
            $icgCodarticulos,
        );
    }

    /**
     * @param  Collection<int, ProductoCosto>  $costosDelArticulo
     * @param  array<int, string>  $ultimaCompraPorProveedor
     */
    private function mapOne(
        int $codigo,
        ?Producto $producto,
        ?ProductoPrecio $precioPublico,
        Collection $costosDelArticulo,
        array $ultimaCompraPorProveedor = [],
        bool $paraDescatalogadoInactivo = false,
    ): MappedProducto {
        // Corrección 2026-08-27 (SCRUM-799, reporte de Gerencia Test) — reabre la decisión del
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
        $reference = $producto !== null ? ($producto->ref_proveedor ?? "ICG-{$codigo}") : "ICG-{$codigo}";
        $factoryReference = null;

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

        if ($paraDescatalogadoInactivo) {
            // SCRUM-1136 F5 — descatalogado con stock que se crea como producto inactivo: mismos campos que un activo (costo, precio público, marca), sin stock inicial.
            return new MappedProducto(
                icgCodarticulo: $codigo,
                reference: $reference,
                factoryReference: $factoryReference,
                description: $producto->descripcion,
                brand: $producto->marca_nombre,
                priceFull: $precioPublico?->precio_neto,
                cost: $this->costoResolver->resolve($costosDelArticulo, $ultimaCompraPorProveedor),
                excludedReason: null,
                stockInicial: null,
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
                stockInicial: $producto->stock_al4,
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
                stockInicial: $producto->stock_al4,
            );
        }

        return new MappedProducto(
            icgCodarticulo: $codigo,
            reference: $reference,
            factoryReference: $factoryReference,
            description: $producto->descripcion,
            brand: $producto->marca_nombre,
            priceFull: $precioPublico->precio_neto,
            cost: $this->costoResolver->resolve($costosDelArticulo, $ultimaCompraPorProveedor),
            excludedReason: null,
            stockInicial: $producto->stock_al4,
        );
    }
}
