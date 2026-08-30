<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-799 — corrección de DATA (no de mapeo) para los productos que YA fueron migrados desde
 * ICG con la asignación vieja (`reference` = ID sintético `"ICG-{codigo}"`, `factory_reference` =
 * código real del fabricante). `ProductoMapper` ya no vuelve a generar ese cruce (fix aparte,
 * mismo ticket) — este servicio corrige, una sola vez por entorno, lo que quedó mal en las filas
 * ya cargadas.
 *
 * Alcance: SOLO `legacy.productos` con `catalog_product_id` seteado — es decir, `catalog_products`
 * cargados por este pipeline (mismo criterio conservador que `CatalogProductLoader`). Nunca toca
 * un producto creado a mano en Compras/Inventario, aunque su `reference` coincida por casualidad
 * con el código de fabricante de otro artículo migrado (ver `swapOne()` — ese caso cuenta como
 * colisión, se excluye).
 *
 * Por fila:
 *  - Si `factory_reference` ya es `null` → nada que corregir (o nunca tuvo REFPROVEEDOR real, o ya
 *    se corrigió en una corrida anterior de este mismo servicio — idempotente).
 *  - Si el valor que pasaría a `reference` (el `factory_reference` actual) ya está en uso por OTRO
 *    `catalog_products` → excluido, decisión explícita de Luis (2026-08-27): "excluir y revisar a
 *    mano", nunca desambiguar solo ni pisar el otro registro.
 *  - Si no hay colisión → swap real: `reference` pasa a valer el `factory_reference` actual,
 *    `factory_reference` queda `null` (decisión de Luis: no dejar el ID sintético viejo ahí, no
 *    sirve como dato de fábrica).
 */
final class ProductoReferenceSwapService
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{
     *   candidatos: int,
     *   corregidos: int,
     *   sin_cambios: int,
     *   excluidos: int,
     *   errores: int,
     *   details: list<array{icg_codarticulo: int, catalog_product_id: int|null, action: string, reason: string|null}>,
     * }
     */
    public function swap(): array
    {
        $candidatos = 0;
        $corregidos = 0;
        $sinCambios = 0;
        $excluidos  = 0;
        $errores    = 0;
        $details    = [];

        Producto::query()
            ->whereNotNull('catalog_product_id')
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($productos) use (&$candidatos, &$corregidos, &$sinCambios, &$excluidos, &$errores, &$details): void {
                foreach ($productos as $producto) {
                    $candidatos++;

                    try {
                        [$action, $reason] = $this->swapOne($producto);
                    } catch (Throwable $e) {
                        $errores++;
                        $details[] = [
                            'icg_codarticulo'    => $producto->icg_codarticulo,
                            'catalog_product_id' => $producto->catalog_product_id,
                            'action'             => 'error',
                            'reason'             => $e->getMessage(),
                        ];
                        continue;
                    }

                    match ($action) {
                        'swapped'     => $corregidos++,
                        'sin_cambios' => $sinCambios++,
                        'excluded'    => $excluidos++,
                    };

                    $details[] = [
                        'icg_codarticulo'    => $producto->icg_codarticulo,
                        'catalog_product_id' => $producto->catalog_product_id,
                        'action'             => $action,
                        'reason'             => $reason,
                    ];
                }
            });

        return [
            'candidatos'  => $candidatos,
            'corregidos'  => $corregidos,
            'sin_cambios' => $sinCambios,
            'excluidos'   => $excluidos,
            'errores'     => $errores,
            'details'     => $details,
        ];
    }

    /** @return array{0: 'swapped'|'sin_cambios'|'excluded', 1: string|null} */
    private function swapOne(Producto $producto): array
    {
        /** @var CatalogProduct|null $catalogProduct */
        $catalogProduct = CatalogProduct::query()->find($producto->catalog_product_id);

        if ($catalogProduct === null) {
            // El link a catalog_products está roto (borrado por fuera del pipeline) — no es un
            // caso normal, se cuenta como excluido para que alguien lo revise, nunca se asume.
            return ['excluded', 'legacy.productos.catalog_product_id apunta a un catalog_products que ya no existe'];
        }

        $oldReference        = $catalogProduct->reference;
        $oldFactoryReference = $catalogProduct->factory_reference;

        if ($oldFactoryReference === null) {
            return ['sin_cambios', 'factory_reference ya es null — nunca tuvo REFPROVEEDOR real o ya se corrigió antes'];
        }

        $collision = CatalogProduct::query()
            ->where('reference', $oldFactoryReference)
            ->where('id', '!=', $catalogProduct->id)
            ->exists();

        if ($collision) {
            return ['excluded', "colisión: \"{$oldFactoryReference}\" ya existe como reference en otro catalog_product (mismo código de fabricante entre proveedores, o choca con un producto cargado a mano) — revisar a mano"];
        }

        DB::transaction(function () use ($catalogProduct, $oldFactoryReference): void {
            $catalogProduct->reference         = $oldFactoryReference;
            $catalogProduct->factory_reference = null;
            $catalogProduct->save();
        });

        return ['swapped', null];
    }
}
