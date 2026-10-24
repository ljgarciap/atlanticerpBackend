<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Producto;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F5, RF2 — equivalencia por referencia entre un artículo descatalogado de ICG (con stock) y un artículo ACTIVO ya presente en `legacy.productos`.
 *
 * Regla: `ref_proveedor` recortada y comparada sin distinguir mayúsculas; una referencia solo es "útil" si contiene al menos un carácter alfanumérico y no es uno de
 * los tokens genéricos de `REFERENCIAS_GENERICAS` (lista técnica, no de negocio: valores de relleno típicos como 'n/a'). Una referencia no útil equivale a "sin referencia".
 *
 * DISEÑO (Senior Review H1, decisión del Arquitecto): el descatalogado equivalente guarda SOLO `equivalente_icg_codarticulo`; su `catalog_product_id` queda NULL. El catálogo
 * se resuelve SIGUIENDO el vínculo (`catalogoResuelto()` / `consultaConCatalogoResuelto()`, `COALESCE(p.catalog_product_id, e.catalog_product_id)`), así dos filas de staging
 * nunca comparten catálogo y no se rompen `legacy:backfill-stock-bodega-central` (predicado de descatalogado por catálogo) ni `StockDeltaService::verificarCuadre()` (suma por catálogo).
 * El mapeo de stock posterior debe usar esa resolución. Nunca se duplica `reference`.
 *
 * Casos que NO se vinculan y se reportan para revisión manual (nunca se adivina):
 *  - `ambiguo`: la referencia coincide con MÁS DE UN activo (tengan o no catálogo).
 *  - `conflicto`: el descatalogado ya tiene un `catalog_product_id` propio (p. ej. se creó inactivo antes de aparecer el activo).
 * Un activo único que coincide pero aún no tiene `catalog_product_id` sí deja el vínculo; el verificador lo señala como "no resuelto" hasta que el activo se cargue.
 *
 * `calcular()` solo lee; `aplicar()` escribe y es idempotente (re-correr no cambia nada).
 */
final class DescatalogadoEquivalenciaService
{
    /** Lote de referencias por consulta: pocos parámetros, nunca cerca del límite de Postgres. */
    private const LOTE = 500;

    /**
     * Valores de relleno comunes (ya normalizados: recortados y en minúscula) que, aunque tengan letras, no identifican un producto. Lista técnica para no vincular
     * productos distintos por una referencia genérica compartida; no es un umbral ni una regla de negocio.
     *
     * @var list<string>
     */
    private const REFERENCIAS_GENERICAS = ['n/a', 'na', 'n.a.', 's/r', 'sr', 's/n', 'sn', 'sin ref', 'sin referencia', 'none', 'null', 'tbd'];

    /** @return string|null referencia útil normalizada (recortada, minúscula) o null si no hay una referencia útil (vacía, sin alfanuméricos o genérica) */
    public static function normalizar(?string $referencia): ?string
    {
        if ($referencia === null) {
            return null;
        }
        $limpia = mb_strtolower(trim($referencia));

        if ($limpia === '' || preg_match('/[\p{L}\p{N}]/u', $limpia) !== 1 || in_array($limpia, self::REFERENCIAS_GENERICAS, true)) {
            return null;
        }

        return $limpia;
    }

    /**
     * @param  list<int> $codarticulos descatalogados a resolver
     * @return array{
     *   vinculos: array<int, array{equivalente: int, catalog_product_id: int|null}>,
     *   pares: list<array{icg_codarticulo: int, referencia: string, equivalente: int, catalog_product_id: int|null}>,
     *   ambiguos: list<int>,
     *   conflictos: list<int>,
     *   sin_equivalente: list<int>,
     * }
     */
    public function calcular(array $codarticulos): array
    {
        $vinculos        = [];
        $pares           = [];
        $ambiguos        = [];
        $conflictos      = [];
        $sinEquivalente  = [];

        $descatalogados = Producto::query()
            ->where('descatalogado', true)
            ->whereIn('icg_codarticulo', $codarticulos)
            ->get(['icg_codarticulo', 'ref_proveedor', 'catalog_product_id']);

        $porReferencia = [];
        foreach ($descatalogados as $d) {
            $ref = self::normalizar($d->ref_proveedor);
            if ($ref === null) {
                $sinEquivalente[] = $d->icg_codarticulo;
                continue;
            }
            $porReferencia[$ref][] = $d;
        }

        $activos = $this->activosPorReferencia(array_keys($porReferencia));

        foreach ($porReferencia as $ref => $lista) {
            foreach ($lista as $d) {
                $coinciden = $activos[$ref] ?? [];
                if ($coinciden === []) {
                    $sinEquivalente[] = $d->icg_codarticulo;
                    continue;
                }

                // Más de un activo con la misma referencia (haya o no catálogo): ambiguo SIEMPRE, no se vincula.
                if (count($coinciden) > 1) {
                    $ambiguos[] = $d->icg_codarticulo;
                    continue;
                }

                // El equivalente no puede tener catálogo propio (se resuelve por el vínculo): si ya lo tiene, se reporta.
                if ($d->catalog_product_id !== null) {
                    $conflictos[] = $d->icg_codarticulo;
                    continue;
                }

                $activo = $coinciden[0];
                $vinculos[$d->icg_codarticulo] = ['equivalente' => $activo['icg_codarticulo'], 'catalog_product_id' => $activo['catalog_product_id']];
                $pares[] = [
                    'icg_codarticulo'    => $d->icg_codarticulo,
                    'referencia'         => (string) $d->ref_proveedor,
                    'equivalente'        => $activo['icg_codarticulo'],
                    'catalog_product_id' => $activo['catalog_product_id'],
                ];
            }
        }

        sort($ambiguos);
        sort($conflictos);
        sort($sinEquivalente);

        return ['vinculos' => $vinculos, 'pares' => $pares, 'ambiguos' => $ambiguos, 'conflictos' => $conflictos, 'sin_equivalente' => $sinEquivalente];
    }

    /**
     * Escribe los vínculos calculados (SOLO `equivalente_icg_codarticulo`; `catalog_product_id` no se toca). Devuelve cuántas filas cambiaron (0 en una segunda corrida).
     *
     * @param array<int, array{equivalente: int, catalog_product_id: int|null}> $vinculos
     */
    public function aplicar(array $vinculos): int
    {
        if ($vinculos === []) {
            return 0;
        }

        return DB::transaction(function () use ($vinculos): int {
            $cambiadas = 0;
            foreach (Producto::query()->where('descatalogado', true)->whereIn('icg_codarticulo', array_keys($vinculos))->get() as $producto) {
                $equivalente = $vinculos[$producto->icg_codarticulo]['equivalente'];
                if ($producto->equivalente_icg_codarticulo === $equivalente) {
                    continue;
                }

                $producto->equivalente_icg_codarticulo = $equivalente;
                $producto->save();
                $cambiadas++;
            }

            return $cambiadas;
        });
    }

    /** `catalog_products.id` al que resuelve un artículo: el propio, o el de su equivalente activo; null si no resuelve. */
    public function catalogoResuelto(int $icgCodarticulo): ?int
    {
        return $this->catalogosResueltos([$icgCodarticulo])[$icgCodarticulo] ?? null;
    }

    /**
     * @param  list<int> $codigos
     * @return array<int, int|null> icg_codarticulo => catalog_product_id resuelto (null si no resuelve)
     */
    public function catalogosResueltos(array $codigos): array
    {
        $out = array_fill_keys($codigos, null);
        foreach ($this->consultaConCatalogoResuelto()->whereIn('p.icg_codarticulo', $codigos)->get() as $fila) {
            $id = $fila->getAttribute('catalog_resuelto');
            $out[$fila->icg_codarticulo] = $id === null ? null : (int) $id;
        }

        return $out;
    }

    /**
     * Consulta reutilizable (para el mapeo de stock): `legacy.productos p` con la columna `catalog_resuelto` = `COALESCE(p.catalog_product_id, e.catalog_product_id)`,
     * donde `e` es el activo equivalente (`p.equivalente_icg_codarticulo`).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Producto>
     */
    public function consultaConCatalogoResuelto(): \Illuminate\Database\Eloquent\Builder
    {
        $tabla = (new Producto())->getTable();

        return Producto::query()
            ->from("{$tabla} as p")
            ->leftJoin("{$tabla} as e", 'e.icg_codarticulo', '=', 'p.equivalente_icg_codarticulo')
            ->select('p.*')
            ->selectRaw('COALESCE(p.catalog_product_id, e.catalog_product_id) as catalog_resuelto');
    }

    /**
     * Activos de `legacy.productos` (descatalogado = false) agrupados por referencia normalizada.
     *
     * @param  list<string> $referencias ya normalizadas
     * @return array<string, list<array{icg_codarticulo: int, catalog_product_id: int|null}>>
     */
    private function activosPorReferencia(array $referencias): array
    {
        $out = [];
        foreach (array_chunk($referencias, self::LOTE) as $lote) {
            $filas = Producto::query()
                ->where('descatalogado', false)
                ->whereNotNull('ref_proveedor')
                ->whereRaw('lower(trim(ref_proveedor)) in (' . implode(',', array_fill(0, count($lote), '?')) . ')', $lote)
                ->get(['icg_codarticulo', 'ref_proveedor', 'catalog_product_id']);

            foreach ($filas as $f) {
                $ref = self::normalizar($f->ref_proveedor);
                if ($ref === null) {
                    continue;
                }
                $out[$ref][] = ['icg_codarticulo' => $f->icg_codarticulo, 'catalog_product_id' => $f->catalog_product_id];
            }
        }

        return $out;
    }
}
