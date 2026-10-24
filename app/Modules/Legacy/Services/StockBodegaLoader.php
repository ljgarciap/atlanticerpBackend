<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Models\AlmacenEquivalencia;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F6 — carga ÚNICA del stock de ICG (`legacy.stock_almacen`) a `bodega.product_warehouse_stock` + Kardex, por almacén confirmado en
 * `legacy.almacen_equivalencias`. ICG está congelado: no hay sync, solo esta carga (y su reversa).
 *
 * Reglas (spec `docs/specs/icg-stock-a-bodega-f6.md`, diseño `docs/architecture/icg-stock-a-bodega-f6-diseno.md`):
 *  - Producto: `COALESCE(p.catalog_product_id, e.catalog_product_id)` (el descatalogado equivalente suma sobre el activo, ver F5). Sin catálogo resoluble → no se carga, se lista.
 *  - Cantidad: Σ de `stock` por (producto de catálogo, bodega) — varias tallas/colores o un equivalente suman — redondeada con PHP_ROUND_HALF_UP (mitad lejos de cero);
 *    los negativos se cargan tal cual (decisión de Luis); resultado 0 → sin fila.
 *  - Exclusiones: `config('legacy.carga_stock_excluir_codarticulos')` (2999, 68602), `CODALMACEN` vacío (nunca está en un almacén confirmado), stock nulo o 0.
 *  - Nunca pisa: solo crea (producto, bodega) que no existen; el INSERT usa ON CONFLICT DO NOTHING y un lote que inserte menos de lo esperado aborta.
 *  - Destino: Bodega Central o una bodega `solo_consulta` aborta antes de escribir.
 *
 * DECISIÓN R2 (Kardex): `KardexService::registrar()` SÍ acepta un Ajuste negativo con saldo 0 (su único piso es para Reubicación; probado en
 * `StockBodegaCargaTest`). No se usa fila por fila porque haría ~5 consultas y 3 filas de `audit_logs` por producto (miles de filas de auditoría ruido) y bloquearía fila por fila;
 * en su lugar el loader escribe en SET-BASED, en la misma transacción, exactamente lo mismo que `registrar()`: la fila de stock, la línea de Kardex (`Ajuste`,
 * `saldo_inicial = 0`, `saldo_resultante = quantity`) y el mismo delta firmado sobre el agregado `catalog_products.stock_quantity` (SCRUM-429: el "Disponible"
 * deriva de ese agregado menos lo que hay en bodegas `excluye_disponible`, así que cargar Merma/Reclamos/Reserva sin subir el agregado lo dejaría descuadrado).
 * El rastro de auditoría es UN evento resumen por almacén.
 */
final class StockBodegaLoader
{
    public const BODEGA_CENTRAL = 'Bodega Central';

    /** `inventory_movements.referencia_entidad_tipo` de las líneas de la carga: así la reversa y la verificación las identifican sin listas de ids. */
    public const ENTIDAD_KARDEX = 'icg_carga_inicial';

    /** Solo para pruebas: se invoca dentro de la transacción tras escribir y ANTES de verificar (simula una discrepancia). Nunca se asigna en producción. */
    public static ?\Closure $antesDeVerificar = null;

    /**
     * Equivalencias CONFIRMADAS (con bodega) validadas: la bodega existe, no es Bodega Central ni `solo_consulta`. Aborta (RuntimeException) ante cualquier problema,
     * antes de escribir nada.
     *
     * @param  list<string> $solo códigos de almacén ICG a incluir (vacío = todos los confirmados)
     * @return array<string, array{warehouse_id: int, warehouse: string, excluye_disponible: bool}> indexado por codalmacen ICG
     */
    public function destinos(array $solo = []): array
    {
        $solo = array_map('strtoupper', $solo);
        $query = AlmacenEquivalencia::query()->where('estado', AlmacenEquivalencia::ESTADO_CONFIRMADO)->orderBy('codalmacen_icg');
        if ($solo !== []) {
            $query->whereIn('codalmacen_icg', $solo);
        }

        $destinos = [];
        foreach ($query->get() as $eq) {
            if ($eq->warehouse_id === null) {
                throw new RuntimeException("La equivalencia de {$eq->codalmacen_icg} está confirmada pero no tiene bodega.");
            }
            $bodega = Warehouse::query()->find($eq->warehouse_id);
            if ($bodega === null) {
                throw new RuntimeException("La bodega {$eq->warehouse_id} de {$eq->codalmacen_icg} no existe.");
            }
            if ($bodega->name === self::BODEGA_CENTRAL) {
                throw new RuntimeException("{$eq->codalmacen_icg} apunta a " . self::BODEGA_CENTRAL . ': su stock ya se cargó (SCRUM-1016/1114), esta carga no la toca.');
            }
            if ($bodega->solo_consulta) {
                throw new RuntimeException("{$eq->codalmacen_icg} apunta a la bodega solo_consulta \"{$bodega->name}\": no admite stock.");
            }
            $destinos[(string) $eq->codalmacen_icg] = ['warehouse_id' => (int) $bodega->id, 'warehouse' => (string) $bodega->name, 'excluye_disponible' => (bool) $bodega->excluye_disponible];
        }

        foreach ($solo as $codigo) {
            if (! isset($destinos[$codigo])) {
                throw new RuntimeException("El almacén {$codigo} no tiene una equivalencia CONFIRMADA en legacy.almacen_equivalencias.");
            }
        }

        return $destinos;
    }

    /**
     * Solo lecturas. `filas` = lo que se SEMBRARÍA (producto, cantidad); `todas` = todas las filas elegibles (incluidas las que ya existen), para verificar.
     *
     * @return array{
     *   filas: list<array{cp: int, qty: int}>,
     *   todas: array<int, int>,
     *   sin_catalogo: list<array{codarticulo: int, stock: float, descatalogado: bool|null, en_staging: bool}>,
     *   excluidos: list<array{codarticulo: int, stock: float}>,
     *   ya_existian: int, en_cero: int, positivas: int, negativas: int, n_negativos: int, suma: int, valor_costo: float, huella: string
     * }
     */
    public function planear(string $codalmacen, int $warehouseId): array
    {
        $productos = $this->tabla('productos');
        $stock = $this->tabla('stock_almacen');

        $filas = DB::select(
            "SELECT s.codarticulo, s.stock, p.icg_codarticulo IS NOT NULL AS en_staging, p.descatalogado,
                    COALESCE(p.catalog_product_id, e.catalog_product_id) AS cp
             FROM {$stock} s
             LEFT JOIN {$productos} p ON p.icg_codarticulo = s.codarticulo
             LEFT JOIN {$productos} e ON e.icg_codarticulo = p.equivalente_icg_codarticulo
             WHERE s.codalmacen = ? AND s.stock IS NOT NULL AND s.stock <> 0
             ORDER BY s.codarticulo, s.talla, s.color",
            [$codalmacen],
        );

        $excluidosCfg = array_map('intval', (array) config('legacy.carga_stock_excluir_codarticulos', []));
        $existentes = array_flip(array_map('intval', DB::table((new ProductWarehouseStock())->getTable())->where('warehouse_id', $warehouseId)->pluck('catalog_product_id')->all()));
        $catalogos = array_flip(array_map('intval', DB::table((new \App\Modules\VentasDiseno\Models\CatalogProduct())->getTable())->whereIn('id', array_values(array_unique(array_filter(array_map(
            static fn (\stdClass $f): ?int => $f->cp !== null ? (int) $f->cp : null,
            $filas,
        )))))->pluck('id')->all()));

        $porCp = [];
        $sinCatalogo = [];
        $excluidos = [];
        foreach ($filas as $f) {
            $codigo = (int) $f->codarticulo;
            $stockIcg = (float) $f->stock;
            if (in_array($codigo, $excluidosCfg, true)) {
                $excluidos[] = ['codarticulo' => $codigo, 'stock' => $stockIcg];
                continue;
            }
            if ($f->cp === null || ! isset($catalogos[(int) $f->cp])) {
                $sinCatalogo[] = ['codarticulo' => $codigo, 'stock' => $stockIcg, 'descatalogado' => $f->descatalogado === null ? null : (bool) $f->descatalogado, 'en_staging' => (bool) $f->en_staging];
                continue;
            }
            $porCp[(int) $f->cp] = ($porCp[(int) $f->cp] ?? 0.0) + $stockIcg;
        }
        ksort($porCp);

        $todas = [];
        $enCero = 0;
        foreach ($porCp as $cp => $suma) {
            $qty = (int) round($suma, 0, PHP_ROUND_HALF_UP);
            if ($qty === 0) {
                $enCero++;
                continue;
            }
            $todas[$cp] = $qty;
        }

        $sembrar = [];
        $yaExistian = 0;
        foreach ($todas as $cp => $qty) {
            if (isset($existentes[$cp])) {
                $yaExistian++;
                continue;
            }
            $sembrar[] = ['cp' => $cp, 'qty' => $qty];
        }

        $costos = $sembrar === [] ? [] : DB::table((new \App\Modules\VentasDiseno\Models\CatalogProduct())->getTable())->whereIn('id', array_column($sembrar, 'cp'))->pluck('cost', 'id')->all();
        $valor = 0.0;
        $pos = $neg = $nNeg = $suma = 0;
        foreach ($sembrar as $s) {
            $suma += $s['qty'];
            if ($s['qty'] > 0) {
                $pos += $s['qty'];
            } else {
                $neg += $s['qty'];
                $nNeg++;
            }
            $valor += $s['qty'] * (float) ($costos[$s['cp']] ?? 0);
        }

        return [
            'filas'        => $sembrar,
            'todas'        => $todas,
            'sin_catalogo' => $sinCatalogo,
            'excluidos'    => $excluidos,
            'ya_existian'  => $yaExistian,
            'en_cero'      => $enCero,
            'positivas'    => $pos,
            'negativas'    => $neg,
            'n_negativos'  => $nNeg,
            'suma'         => $suma,
            'valor_costo'  => round($valor, 2),
            'huella'       => md5(implode(',', array_map(static fn (array $s): string => $s['cp'] . ':' . $s['qty'], $sembrar))),
        ];
    }

    /**
     * Escribe el plan de UN almacén en UNA transacción: stock + Kardex + agregado + evento de auditoría, con verificación antes/después y ROLLBACK ante cualquier discrepancia.
     *
     * @param  array<string, mixed> $plan resultado de `planear()`
     * @return int filas sembradas
     */
    public function aplicar(string $codalmacen, int $warehouseId, array $plan, int $lote, string $operador, string $slug): int
    {
        return DB::transaction(function () use ($codalmacen, $warehouseId, $plan, $lote, $operador, $slug): int {
            // El plan se recalcula dentro de la transacción: si la selección cambió desde el dry-run, se aborta.
            $actual = $this->planear($codalmacen, $warehouseId);
            if ($actual['huella'] !== $plan['huella'] || $actual['suma'] !== $plan['suma']) {
                throw new RuntimeException('la selección cambió entre el análisis y la escritura (huella distinta): repite el dry-run.');
            }

            /** @var list<array{cp: int, qty: int}> $filas */
            $filas = $plan['filas'];
            if ($filas === []) {
                return 0;
            }
            $ids = array_column($filas, 'cp');
            $stockT = (new ProductWarehouseStock())->getTable();
            $movT = (new InventoryMovement())->getTable();
            $cpT = (new \App\Modules\VentasDiseno\Models\CatalogProduct())->getTable();

            $antes = $this->estado($stockT, $movT, $cpT, $warehouseId, $ids);
            if ($antes['filas'] !== 0) {
                throw new RuntimeException("{$antes['filas']} filas ya existen para los productos del plan (cambió la selección).");
            }

            $texto = "Carga inicial desde ICG (SCRUM-1136 F6) — almacén {$codalmacen}";
            $insertadas = 0;
            foreach (array_chunk($filas, max(1, $lote)) as $pedazo) {
                $valores = [];
                $bind = [];
                foreach ($pedazo as $s) {
                    $valores[] = '(?::bigint, ?::bigint, ?::integer)';
                    array_push($bind, $s['cp'], $warehouseId, $s['qty']);
                }
                $lista = implode(', ', $valores);

                $n = DB::affectingStatement(
                    "INSERT INTO {$stockT} (catalog_product_id, warehouse_id, quantity, created_at, updated_at)
                     SELECT v.cp, v.wh, v.qty, now(), now() FROM (VALUES {$lista}) AS v(cp, wh, qty)
                     ON CONFLICT (catalog_product_id, warehouse_id) DO NOTHING",
                    $bind,
                );
                if ($n !== count($pedazo)) {
                    throw new RuntimeException("el lote insertó {$n} de " . count($pedazo) . ' filas (una fila apareció concurrentemente).');
                }
                $insertadas += $n;

                DB::insert(
                    "INSERT INTO {$movT} (catalog_product_id, warehouse_id, tipo, cantidad, referencia_texto, referencia_entidad_tipo, referencia_entidad_id, realizado_por, saldo_inicial, saldo_resultante, created_at)
                     SELECT v.cp, v.wh, ?, v.qty, ?, ?, NULL, NULL, 0, v.qty, now() FROM (VALUES {$lista}) AS v(cp, wh, qty)",
                    [InventoryMovement::TIPO_AJUSTE, $texto, self::ENTIDAD_KARDEX, ...$bind],
                );

                // SCRUM-429: mismo delta firmado sobre el agregado del producto que aplica KardexService::registrar().
                DB::update(
                    "UPDATE {$cpT} c SET stock_quantity = COALESCE(c.stock_quantity, 0) + v.qty, updated_at = now()
                     FROM (VALUES {$lista}) AS v(cp, wh, qty) WHERE c.id = v.cp",
                    $bind,
                );
            }

            if (self::$antesDeVerificar !== null) {
                (self::$antesDeVerificar)();
            }

            $despues = $this->estado($stockT, $movT, $cpT, $warehouseId, $ids);
            $fallos = [];
            if ($insertadas !== count($filas)) {
                $fallos[] = "insertadas {$insertadas}, esperadas " . count($filas);
            }
            if ($despues['filas'] !== count($filas) || $despues['suma'] !== $plan['suma']) {
                $fallos[] = "stock de la bodega: {$despues['filas']} filas / Σ {$despues['suma']}, esperado " . count($filas) . " / Σ {$plan['suma']}";
            }
            if ($despues['movimientos'] !== count($filas) || $despues['suma_mov'] !== $plan['suma']) {
                $fallos[] = "Kardex: {$despues['movimientos']} líneas / Σ {$despues['suma_mov']}, esperado " . count($filas) . " / Σ {$plan['suma']}";
            }
            if ($despues['agregado'] !== $antes['agregado'] + $plan['suma']) {
                $fallos[] = "Σ stock_quantity {$despues['agregado']}, esperado " . ($antes['agregado'] + $plan['suma']);
            }
            if ($fallos !== []) {
                throw new RuntimeException('verificación fallida: ' . implode('; ', $fallos));
            }

            AuditLog::query()->create([
                'user_id'     => null,
                'action'      => AuditService::LEGACY_CARGAR_STOCK_BODEGAS,
                'entity_type' => 'product_warehouse_stock',
                'metadata'    => [
                    'operador'           => $operador,
                    'usuario_so'         => get_current_user(),
                    'host'               => (string) gethostname(),
                    'entorno'            => (string) config('app.env'),
                    'tenant'             => $slug,
                    'almacen_icg'        => $codalmacen,
                    'warehouse_id'       => $warehouseId,
                    'filas_sembradas'    => $insertadas,
                    'unidades_positivas' => $plan['positivas'],
                    'unidades_negativas' => $plan['negativas'],
                    'productos_negativos' => $plan['n_negativos'],
                    'ya_existian'        => $plan['ya_existian'],
                    'sin_catalogo'       => count($plan['sin_catalogo']),
                    'excluidos'          => count($plan['excluidos']),
                    'huella_seleccion'   => $plan['huella'],
                ],
            ]);

            return $insertadas;
        });
    }

    /**
     * Compara lo esperado (todas las filas elegibles) con lo que hay en la bodega. SOLO LEE.
     *
     * @param  array<string, mixed> $plan
     * @return array{esperado_filas: int, esperado_suma: int, real_filas: int, real_suma: int, distintas: int}
     */
    public function verificar(int $warehouseId, array $plan): array
    {
        /** @var array<int, int> $todas */
        $todas = $plan['todas'];
        $reales = $todas === [] ? [] : DB::table((new ProductWarehouseStock())->getTable())->where('warehouse_id', $warehouseId)->whereIn('catalog_product_id', array_keys($todas))->pluck('quantity', 'catalog_product_id')->all();

        $distintas = 0;
        foreach ($todas as $cp => $qty) {
            if (! isset($reales[$cp]) || (int) $reales[$cp] !== $qty) {
                $distintas++;
            }
        }

        return [
            'esperado_filas' => count($todas),
            'esperado_suma'  => array_sum($todas),
            'real_filas'     => count($reales),
            'real_suma'      => (int) array_sum(array_map('intval', $reales)),
            'distintas'      => $distintas,
        ];
    }

    /**
     * @param  list<int> $ids
     * @return array{filas: int, suma: int, movimientos: int, suma_mov: int, agregado: int}
     */
    private function estado(string $stockT, string $movT, string $cpT, int $warehouseId, array $ids): array
    {
        $lista = implode(',', array_map('intval', $ids));
        $s = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(quantity), 0) AS suma FROM {$stockT} WHERE warehouse_id = ? AND catalog_product_id IN ({$lista})", [$warehouseId]);
        $m = DB::selectOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(cantidad), 0) AS suma FROM {$movT} WHERE warehouse_id = ? AND referencia_entidad_tipo = ? AND catalog_product_id IN ({$lista})",
            [$warehouseId, self::ENTIDAD_KARDEX],
        );
        $a = DB::selectOne("SELECT COALESCE(SUM(COALESCE(stock_quantity, 0)), 0) AS suma FROM {$cpT} WHERE id IN ({$lista})");

        return ['filas' => (int) $s->n, 'suma' => (int) $s->suma, 'movimientos' => (int) $m->n, 'suma_mov' => (int) $m->suma, 'agregado' => (int) $a->suma];
    }

    private function tabla(string $nombre): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? $nombre : "{$tenant->slug}_legacy.{$nombre}";
    }
}
