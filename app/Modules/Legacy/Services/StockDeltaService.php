<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1114 (épica SCRUM-749) — sincronización de stock por DELTA mientras ICG y AtlanticERP operan en
 * paralelo. AtlanticERP es la fuente de verdad; de ICG solo se importa su variación desde la última lectura:
 *
 *     delta = round(AL4_hoy) - legacy.productos.stock_al4_aplicado
 *
 * y se suma (con signo) a lo que AtlanticERP ya tenga. Ejemplo canónico: base 100, ICG pasa a 90 (delta
 * -10), AtlanticERP movió -5 → AtlanticERP queda en 100 - 5 - 10 = 85. El delta puede ser positivo (entradas,
 * devoluciones, ajustes en ICG) o negativo; no distingue el origen del movimiento.
 *
 * Cada delta se registra con `KardexService::registrar()` sobre Bodega Central (AL4 = Central) como un
 * movimiento `Ajuste` con `referencia_entidad_tipo = 'legacy.sync_run'`: ese servicio ya aplica el MISMO
 * delta firmado a `product_warehouse_stock` y a `catalog_products.stock_quantity` en una transacción,
 * así que las dos fuentes no se separan. Se descartó `Entrada`/`Salida`: `BodegaHomeService::
 * mayorRotacion()` cuenta todo movimiento `Salida` como rotación real y las ventas de ICG la inflarían.
 *
 * La base se actualiza en la MISMA transacción que el movimiento (idempotencia: o se aplica todo o
 * nada, y una segunda corrida inmediata no encuentra delta). Cada producto va en su propia transacción:
 * el fallo de uno no frena a los demás.
 *
 * Alcance: solo productos ligados a ICG (`catalog_product_id`), activos y no descatalogados (mismo
 * criterio que `legacy:backfill-stock-bodega-central`). Un inactivo no se toca ni avanza su base.
 * Base `null` (nunca cargado): si AtlanticERP no tiene stock del producto (`stock_quantity` nulo y sin filas
 * por bodega) se trata como 0 y el delta es todo el AL4; si ya tiene stock se RETIENE y se reporta —
 * nunca se suma a ciegas sobre algo que no se sabe de dónde viene. Los negativos se permiten.
 *
 * @phpstan-type Item array{producto_id: int, catalog_product_id: int, icg_codarticulo: int, base: int|null, actual: int, delta: int, tipo: 'delta'|'base', desde_cero: bool}
 * @phpstan-type Plan array{items: list<Item>, resumen: array<string, mixed>}
 */
final class StockDeltaService
{
    /** Tope de ids/filas de muestra que se guardan en el resumen JSON de la corrida. */
    private const MUESTRA = 20;
    private const IDS_MAX = 100;

    public function __construct(private readonly KardexService $kardex)
    {
    }

    /** Id de "Bodega Central" operativa (no `solo_consulta`), o null si no existe. */
    public function bodegaCentralId(): ?int
    {
        $id = Warehouse::query()->operativas()->where('name', 'Bodega Central')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Calcula el delta de cada producto elegible SIN escribir nada.
     *
     * @param array<int, float> $al4 stock actual de AL4 por CODARTICULO (ausente = sin fila = 0)
     * @return Plan
     */
    public function plan(array $al4): array
    {
        $p = (new Producto())->getTable();
        $c = (new CatalogProduct())->getTable();
        $w = (new ProductWarehouseStock())->getTable();

        $rows = DB::table("{$p} as p")
            ->join("{$c} as c", 'c.id', '=', 'p.catalog_product_id')
            ->whereNotNull('p.catalog_product_id')
            ->selectRaw("p.id as producto_id, p.icg_codarticulo, p.catalog_product_id, p.stock_al4_aplicado, p.descatalogado,
                         c.is_active, c.stock_quantity,
                         EXISTS (SELECT 1 FROM {$w} s WHERE s.catalog_product_id = c.id) as tiene_filas")
            ->orderBy('p.id')
            ->cursor();

        $items = [];
        $r = [
            'elegibles' => 0, 'omitidos_inactivos' => 0, 'sin_cambio' => 0, 'con_delta' => 0,
            'desde_cero' => 0, 'base_inicializada' => 0, 'retenidos' => 0, 'sin_fila_al4' => 0,
            'unidades_positivas' => 0, 'unidades_negativas' => 0, 'unidades_abs_total' => 0, 'max_abs_producto' => 0,
        ];
        $retenidos = [];

        foreach ($rows as $row) {
            if (! (bool) $row->is_active || (bool) $row->descatalogado) {
                $r['omitidos_inactivos']++;
                continue;
            }
            $r['elegibles']++;

            $icg = (int) $row->icg_codarticulo;
            if (! array_key_exists($icg, $al4)) {
                $r['sin_fila_al4']++;
            }
            $actual = (int) round($al4[$icg] ?? 0.0);

            $base      = $row->stock_al4_aplicado === null ? null : (int) $row->stock_al4_aplicado;
            $desdeCero = false;
            if ($base === null) {
                if ($row->stock_quantity === null && ! (bool) $row->tiene_filas) {
                    $desdeCero = true;
                } else {
                    $r['retenidos']++;
                    if (count($retenidos) < self::IDS_MAX) {
                        $retenidos[] = (int) $row->catalog_product_id;
                    }
                    continue;
                }
            }

            $delta = $actual - ($base ?? 0);
            $item  = [
                'producto_id'        => (int) $row->producto_id,
                'catalog_product_id' => (int) $row->catalog_product_id,
                'icg_codarticulo'    => $icg,
                'base'               => $base,
                'actual'             => $actual,
                'delta'              => $delta,
                'tipo'               => $delta === 0 ? 'base' : 'delta',
                'desde_cero'         => $desdeCero,
            ];

            if ($delta === 0) {
                if ($base === null) {
                    // Sin movimiento, pero la base pasa de "nunca cargado" a 0 conocido.
                    $r['base_inicializada']++;
                    $items[] = $item;
                } else {
                    $r['sin_cambio']++;
                }
                continue;
            }

            $r['con_delta']++;
            $desdeCero && $r['desde_cero']++;
            $delta > 0 ? $r['unidades_positivas'] += $delta : $r['unidades_negativas'] += $delta;
            $r['unidades_abs_total'] += abs($delta);
            $r['max_abs_producto'] = max($r['max_abs_producto'], abs($delta));
            $items[] = $item;
        }

        $conDelta = array_values(array_filter($items, static fn (array $i): bool => $i['tipo'] === 'delta'));
        usort($conDelta, static fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));
        $r['muestra_mayores'] = array_map(
            static fn (array $i): array => ['icg_codarticulo' => $i['icg_codarticulo'], 'catalog_product_id' => $i['catalog_product_id'], 'base' => $i['base'], 'actual' => $i['actual'], 'delta' => $i['delta']],
            array_slice($conDelta, 0, self::MUESTRA),
        );
        $r['retenidos_ids'] = $retenidos;

        return ['items' => $items, 'resumen' => $r];
    }

    /**
     * Compara el plan contra los umbrales paramétricos. Devuelve el motivo del primero que se supera
     * (la corrida queda `held_for_review` y no aplica nada) o null si está dentro de lo esperado.
     *
     * @param array<string, mixed> $resumen
     */
    public function motivoCircuitBreaker(array $resumen, StockSyncSettings $settings): ?string
    {
        $elegibles = (int) $resumen['elegibles'];
        $pct       = $elegibles > 0 ? ((int) $resumen['con_delta'] / $elegibles) * 100 : 0.0;

        if ($pct > $settings->max_productos_con_delta_pct) {
            return sprintf('%.1f%% de los productos elegibles tienen delta (umbral %.1f%%)', $pct, $settings->max_productos_con_delta_pct);
        }
        if ((int) $resumen['max_abs_producto'] > $settings->max_unidades_abs_por_producto) {
            return sprintf('un producto tiene un delta de %d unidades (umbral %d)', (int) $resumen['max_abs_producto'], $settings->max_unidades_abs_por_producto);
        }
        if ((int) $resumen['unidades_abs_total'] > $settings->max_unidades_abs_total) {
            return sprintf('%d unidades absolutas en total (umbral %d)', (int) $resumen['unidades_abs_total'], $settings->max_unidades_abs_total);
        }

        return null;
    }

    /**
     * Guard contra una respuesta PARCIAL de ICG (no vacía): si las filas de AL4 leídas son menos que
     * `min_filas_icg_pct` % de las de la última corrida buena, lo que falta no debe leerse como stock 0. Sin
     * corrida previa buena no hay con qué comparar (la primera corrida real se revisa con `--dry-run`).
     */
    public function motivoRespuestaParcial(int $filasLeidas, ?int $filasUltimaBuena, StockSyncSettings $settings): ?string
    {
        if ($filasUltimaBuena === null || $filasUltimaBuena <= 0) {
            return null;
        }
        $minimo = (int) ceil($filasUltimaBuena * $settings->min_filas_icg_pct / 100);

        return $filasLeidas < $minimo
            ? sprintf('ICG devolvió %d filas de AL4, menos del %.0f%% de las %d de la última corrida buena (posible respuesta parcial)', $filasLeidas, $settings->min_filas_icg_pct, $filasUltimaBuena)
            : null;
    }

    /**
     * Aplica el plan: un movimiento de kardex + la base, por producto, cada uno en su transacción.
     *
     * @param Plan $plan
     * @return array{aplicados: int, bases_inicializadas: int, omitidos_cambiados: int, errores: int, negativos: int, negativos_ids: list<int>, mensajes_error: list<string>}
     */
    public function apply(array $plan, int $syncRunId, int $bodegaCentralId): array
    {
        $out = ['aplicados' => 0, 'bases_inicializadas' => 0, 'omitidos_cambiados' => 0, 'errores' => 0, 'negativos' => 0, 'negativos_ids' => [], 'mensajes_error' => []];

        foreach ($plan['items'] as $item) {
            try {
                // Los efectos se devuelven desde la transacción y se acumulan SOLO después del commit: un fallo
                // al actualizar la base revierte el movimiento y no deja un contador de "aplicados" inflado.
                /** @var array{resultado: 'delta'|'base'|'cambiada', negativo: bool} $efecto */
                $efecto = DB::transaction(function () use ($item, $syncRunId, $bodegaCentralId): array {
                    /** @var Producto|null $producto */
                    $producto = Producto::query()->lockForUpdate()->find($item['producto_id']);
                    $baseHoy  = $producto?->stock_al4_aplicado;

                    // La base cambió desde que se calculó el plan (otra corrida concurrente): no se aplica
                    // un delta calculado sobre una base vieja — se vuelve a calcular en la próxima corrida.
                    if ($producto === null || ($baseHoy === null ? null : (int) $baseHoy) !== $item['base']) {
                        return ['resultado' => 'cambiada', 'negativo' => false];
                    }

                    $negativo = false;
                    if ($item['tipo'] === 'delta') {
                        $mov = $this->kardex->registrar(
                            $item['catalog_product_id'],
                            $bodegaCentralId,
                            InventoryMovement::TIPO_AJUSTE,
                            $item['delta'],
                            "Ajuste por sincronización ICG (corrida #{$syncRunId})",
                            'legacy.sync_run',
                            $syncRunId,
                            null,
                        );
                        $negativo = $mov->saldo_resultante < 0;
                    }

                    $producto->update(['stock_al4_aplicado' => $item['actual']]);

                    return ['resultado' => $item['tipo'], 'negativo' => $negativo];
                });
            } catch (Throwable $e) {
                $out['errores']++;
                if (count($out['mensajes_error']) < self::MUESTRA) {
                    $out['mensajes_error'][] = "icg {$item['icg_codarticulo']}: " . LegacySyncAlertService::sanitizar($e->getMessage());
                }
                continue;
            }

            match ($efecto['resultado']) {
                'cambiada' => $out['omitidos_cambiados']++,
                'base'     => $out['bases_inicializadas']++,
                default    => $out['aplicados']++,
            };
            if ($efecto['negativo']) {
                $out['negativos']++;
                if (count($out['negativos_ids']) < self::IDS_MAX) {
                    $out['negativos_ids'][] = $item['catalog_product_id'];
                }
            }
        }

        return $out;
    }

    /**
     * Cuánto se aleja AtlanticERP de ICG en Bodega Central, producto por producto: `Central - línea base`. La línea base es
     * el AL4 de ICG ya reflejado, así que la diferencia es lo que AtlanticERP tiene (o le falta) por su cuenta. No cambia al
     * aplicar un delta (el delta mueve Central y la base por igual), por eso sirve igual en `--dry-run` que después de
     * aplicar. Solo informa: no corrige nada, porque AtlanticERP es la fuente de verdad.
     *
     * Cada diferencia se contrasta con el kardex PROPIO de AtlanticERP en Bodega Central (todo movimiento que no sea del sync
     * ni de su reversa, que se cancelan entre sí): la diferencia queda EXPLICADA si es igual a la suma de esos
     * movimientos. Tres grupos: `sin_movimientos` (stock de origen desconocido: datos de prueba o un alta fuera del
     * kardex), `explicados_por_kardex` (la suma de movimientos iguala la diferencia) y `con_residuo` (hay movimientos
     * pero no alcanzan a explicarla; por ejemplo ICG en 0, AtlanticERP en 454 y una sola salida de pedido). `sin_explicar`
     * es el residuo `diferencia - suma de movimientos` de cada producto.
     *
     * @return array{total: int, atlanticerp_con_mas: int, atlanticerp_con_menos: int, unidades_mas: int, unidades_menos: int, sin_movimientos: int, explicados_por_kardex: int, con_residuo: int, unidades_sin_explicar: int, mayores: list<array{icg_codarticulo: int, catalog_product_id: int, icg: int, atlanticerp: int, diferencia: int, kardex_atlanticerp: int, sin_explicar: int, con_movimientos_atlanticerp: bool}>}
     */
    public function divergenciaAtlanticERP(int $bodegaCentralId): array
    {
        $p = (new Producto())->getTable();
        $c = (new CatalogProduct())->getTable();
        $w = (new ProductWarehouseStock())->getTable();
        $m = (new \App\Modules\Bodega\Models\InventoryMovement())->getTable();

        // Movimientos propios de AtlanticERP en Central: todo lo que no es del sync ni de su reversa.
        $propios = "FROM {$m} mv WHERE mv.catalog_product_id = c.id AND mv.warehouse_id = ?
                       AND COALESCE(mv.referencia_entidad_tipo, '') NOT IN ('legacy.sync_run', 'legacy.sync_run_reversa')";

        $rows = DB::table("{$p} as p")
            ->join("{$c} as c", 'c.id', '=', 'p.catalog_product_id')
            ->leftJoin("{$w} as s", static function ($join) use ($bodegaCentralId): void {
                $join->on('s.catalog_product_id', '=', 'c.id')->where('s.warehouse_id', '=', $bodegaCentralId);
            })
            ->whereNotNull('p.stock_al4_aplicado')
            ->where('c.is_active', true)
            ->where('p.descatalogado', false)
            ->whereRaw('COALESCE(s.quantity, 0) <> p.stock_al4_aplicado')
            ->selectRaw(
                "p.icg_codarticulo, c.id as catalog_product_id, p.stock_al4_aplicado as icg, COALESCE(s.quantity, 0) as atlanticerp,
                 (SELECT COUNT(*) {$propios}) as n_mov, COALESCE((SELECT SUM(mv.cantidad) {$propios}), 0) as kardex_atlanticerp",
                [$bodegaCentralId, $bodegaCentralId],
            )
            ->get();

        $out = [
            'total' => 0, 'atlanticerp_con_mas' => 0, 'atlanticerp_con_menos' => 0, 'unidades_mas' => 0, 'unidades_menos' => 0,
            'sin_movimientos' => 0, 'explicados_por_kardex' => 0, 'con_residuo' => 0, 'unidades_sin_explicar' => 0,
        ];
        $todos = [];
        foreach ($rows as $row) {
            $dif      = (int) $row->atlanticerp - (int) $row->icg;
            $kardex   = (int) $row->kardex_atlanticerp;
            $residuo  = $dif - $kardex;
            $conMovs  = (int) $row->n_mov > 0;

            $out['total']++;
            if ($dif > 0) {
                $out['atlanticerp_con_mas']++;
                $out['unidades_mas'] += $dif;
            } else {
                $out['atlanticerp_con_menos']++;
                $out['unidades_menos'] += $dif;
            }
            if (! $conMovs) {
                $out['sin_movimientos']++;
            } elseif ($residuo === 0) {
                $out['explicados_por_kardex']++;
            } else {
                $out['con_residuo']++;
            }
            $out['unidades_sin_explicar'] += abs($residuo);

            $todos[] = [
                'icg_codarticulo'       => (int) $row->icg_codarticulo,
                'catalog_product_id'    => (int) $row->catalog_product_id,
                'icg'                   => (int) $row->icg,
                'atlanticerp'                 => (int) $row->atlanticerp,
                'diferencia'            => $dif,
                'kardex_atlanticerp'          => $kardex,
                'sin_explicar'          => $residuo,
                'con_movimientos_atlanticerp' => $conMovs,
            ];
        }
        // Primero lo que más unidades deja sin explicar: es lo que merece revisión.
        usort($todos, static fn (array $a, array $b): int => [abs($b['sin_explicar']), abs($b['diferencia'])] <=> [abs($a['sin_explicar']), abs($a['diferencia'])]);
        $out['mayores'] = array_slice($todos, 0, self::MUESTRA);

        return $out;
    }

    /**
     * Verificación informativa (no corrige nada): productos ligados a ICG cuyo desglose por bodega no
     * suma `catalog_products.stock_quantity`.
     *
     * @return array{no_cuadran: int, no_cuadran_ids: list<int>}
     */
    public function verificarCuadre(): array
    {
        $p = (new Producto())->getTable();
        $c = (new CatalogProduct())->getTable();
        $w = (new ProductWarehouseStock())->getTable();

        $base = fn () => DB::table("{$c} as c")
            ->join("{$p} as p", 'p.catalog_product_id', '=', 'c.id')
            ->leftJoin("{$w} as s", 's.catalog_product_id', '=', 'c.id')
            ->whereNotNull('c.stock_quantity')
            ->groupBy('c.id', 'c.stock_quantity')
            ->havingRaw('COALESCE(SUM(s.quantity), 0) <> c.stock_quantity');

        // Solo los ids (enteros) de los productos que no cuadran: en la práctica son pocos y, en el peor caso, un
        // entero por producto ligado (~12.000) cabe de sobra en memoria.
        /** @var list<int> $todos */
        $todos = $base()->orderBy('c.id')->pluck('c.id')->map(static fn ($id): int => (int) $id)->all();

        return ['no_cuadran' => count($todos), 'no_cuadran_ids' => array_slice($todos, 0, self::IDS_MAX)];
    }
}
