<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-750 — "Loader-A" (staging). Upsert idempotente en `legacy.productos`/
 * `producto_precios`/`producto_costos` por la llave natural de ICG. Re-correr el mismo extracto
 * dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.productos`: upsert por `icg_codarticulo` (UNIQUE). `catalog_product_id` NUNCA se
 *    incluye en el `$update` de `upsert()` — así una segunda corrida jamás resetea el link que
 *    puso `CatalogProductLoader` en una corrida anterior.
 *  - `legacy.producto_precios`/`producto_costos`: upsert por su UNIQUE compuesto respectivo.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de cada modelo) — el llamador
 * (`App\Console\Commands\LegacySyncProductosCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`.
 */
final class ProductoStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement — un solo
     * `upsert()` con todas las filas de una corrida real (miles de artículos activos, confirmado
     * 2026-08-12 en la Tarea 6 contra ICG real: "number of parameters must be between 0 and 65535")
     * lo revienta apenas el extracto supera ~8000 filas con 8 columnas. 500 deja margen de sobra
     * para las tres tablas (8, 8 y 5 columnas respectivamente) sin acercarse al límite.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en una transacción propia (hallazgo Pre-QA SCRUM-750, 2026-08-12): sin esto, una
     * falla a mitad de camino (ej. `upsertProductos` OK pero `upsertPrecios` falla) dejaba staging
     * a medias, Y — más grave — cualquier statement posterior en la misma conexión (incluido el
     * `legacy.sync_runs` que registra la corrida como fallida) heredaba el estado de transacción
     * abortada de Postgres y fallaba en cascada. `DB::transaction()` hace que un fallo acá emita
     * ROLLBACK (o ROLLBACK TO SAVEPOINT si ya hay una transacción abierta más arriba) antes de
     * re-lanzar la excepción original — quien llama (`LegacySyncProductosCommand`) recibe una
     * conexión limpia para poder registrar la corrida fallida.
     *
     * @return array{productos: int, precios: int, costos: int}
     */
    public function load(ExtractedProductos $extracted): array
    {
        $now = now();

        return DB::transaction(fn (): array => [
            'productos' => $this->upsertProductos($extracted->productos, $now),
            'precios'   => $this->upsertPrecios($extracted->precios, $now),
            'costos'    => $this->upsertCostos($extracted->costos, $now),
        ]);
    }

    /** @param list<ProductoRow> $rows */
    private function upsertProductos(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (ProductoRow $row): array => [
            'icg_codarticulo' => $row->icgCodarticulo,
            'descripcion'     => $row->descripcion,
            'familia_nombre'  => $row->familiaNombre,
            'marca_nombre'    => $row->marcaNombre,
            'ref_proveedor'   => $row->refProveedor,
            'descatalogado'   => $row->descatalogado,
            'stock_al4'       => $row->stockAl4,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Producto::query()->upsert(
                $chunk,
                ['icg_codarticulo'],
                ['descripcion', 'familia_nombre', 'marca_nombre', 'ref_proveedor', 'descatalogado', 'stock_al4', 'updated_at'],
            );
        }

        return count($values);
    }

    /** @param list<ProductoPrecioRow> $rows */
    private function upsertPrecios(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (ProductoPrecioRow $row): array => [
            'icg_codarticulo'    => $row->icgCodarticulo,
            'idtarifav'          => $row->idtarifav,
            'precio_neto'        => $row->precioNeto,
            'precio_bruto'       => $row->precioBruto,
            'descuento'          => $row->descuento,
            'descatalogado_fila' => $row->descatalogadoFila,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            ProductoPrecio::query()->upsert(
                $chunk,
                ['icg_codarticulo', 'idtarifav'],
                ['precio_neto', 'precio_bruto', 'descuento', 'descatalogado_fila', 'updated_at'],
            );
        }

        return count($values);
    }

    /** @param list<ProductoCostoRow> $rows */
    private function upsertCostos(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (ProductoCostoRow $row): array => [
            'icg_codarticulo' => $row->icgCodarticulo,
            'codproveedor'    => $row->codproveedor,
            'costo_neto'      => $row->costoNeto,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            ProductoCosto::query()->upsert(
                $chunk,
                ['icg_codarticulo', 'codproveedor'],
                ['costo_neto', 'updated_at'],
            );
        }

        return count($values);
    }
}
