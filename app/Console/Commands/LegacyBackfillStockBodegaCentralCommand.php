<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1016 (épica SCRUM-749) — refleja el stock de ICG (AL4 = Bodega Central) TAMBIÉN por bodega. SCRUM-921 lo sembró solo en
 * `catalog_products.stock_quantity`; nada creaba las filas de `product_warehouse_stock` fuera de dev (el seeder de demostración es solo local/dev).
 *
 * Selecciona los `catalog_products` LIGADOS a ICG (existe `legacy.productos.catalog_product_id` = su id) con `stock_quantity` no nulo y distinto de 0 y SIN
 * NINGUNA fila en `product_warehouse_stock` (en cualquier bodega: así no pisa lo que Bodega ya gestiona ni lo que el seeder de dev ya creó, ni suma sobre nada) y
 * crea UNA fila (producto, Bodega Central) con `quantity = stock_quantity`; los negativos se reflejan tal cual (decisión de Luis). Nunca toca productos de
 * Lafayette (no están ligados a `legacy.productos`) ni escribe en una bodega distinta de Bodega Central o marcada `solo_consulta`.
 *
 * Se EXCLUYEN los productos inactivos (`catalog_products.is_active = false`) y los descatalogados en ICG (`legacy.productos.descatalogado`): decisión de Luis, no se
 * ven en Compras/Bodega Inventario y no deben aparecer en el listado por bodega; el dry-run los cuenta aparte.
 *
 * Por defecto es DRY-RUN (solo lecturas). `--apply` (con `--operador`) escribe por lotes dentro de UNA transacción, verifica antes/después y hace ROLLBACK ante
 * cualquier discrepancia; es idempotente (la 2ª corrida no encuentra nada). Un evento de auditoría resumen se escribe dentro de la transacción. No bloquea
 * tablas: el INSERT usa `ON CONFLICT DO NOTHING` y una fila que apareció concurrentemente aborta la corrida entera (el conteo del lote no coincide).
 */
final class LegacyBackfillStockBodegaCentralCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    private const BODEGA = 'Bodega Central';

    /** Máximo de ids sembrados que se guardan en el evento de auditoría (sirven para revertir con seguridad; ver el runbook). */
    private const MAX_IDS_AUDITORIA = 5000;

    /** Solo para pruebas: se invoca dentro de la transacción justo ANTES del INSERT (simula cambios concurrentes). Nunca se asigna en producción. */
    public static ?\Closure $antesDeInsertar = null;

    /** Solo para pruebas: se invoca dentro de la transacción tras el INSERT y ANTES de verificar (simula actividad concurrente de Bodega). */
    public static ?\Closure $antesDeVerificar = null;

    /** @var string */
    protected $signature = 'legacy:backfill-stock-bodega-central
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe las filas. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}
        {--lote=500 : Filas por INSERT}';

    /** @var string */
    protected $description = 'SCRUM-1016 — siembra en product_warehouse_stock (Bodega Central) el stock de ICG que hoy vive solo en catalog_products.stock_quantity';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $apply = (bool) $this->option('apply');

        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }
        $lote = filter_var($this->option('lote'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($lote === false) {
            $this->error('--lote debe ser un entero entre 1 y 5000.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply, $lote);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply, int $lote): int
    {
        $bodegaId = $this->resolverBodega();
        if ($bodegaId === null) {
            return self::FAILURE;
        }

        $t = [
            'cp'     => (new CatalogProduct())->getTable(),
            'stock'  => (new ProductWarehouseStock())->getTable(),
            'legacy' => (new Producto())->getTable(),
        ];

        $plan = $this->analizar($t, $bodegaId);
        $this->reportar($plan);

        if ($plan['sembrar'] === 0) {
            $this->info('Nada que sembrar.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>".');

            return self::SUCCESS;
        }

        try {
            $resultado = DB::transaction(fn (): array => $this->aplicar($t, $plan, $bodegaId, $slug, $lote));
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se escribió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Sembradas {$resultado['filas']} filas en " . self::BODEGA . ' y verificadas.');

        return self::SUCCESS;
    }

    /** Exactamente UNA bodega "Bodega Central", y no `solo_consulta`; si no, aborta (nunca escribe en otra bodega). */
    private function resolverBodega(): ?int
    {
        $bodegas = Warehouse::query()->where('name', self::BODEGA)->get();
        if ($bodegas->count() !== 1) {
            $this->error('Se esperaba exactamente UNA bodega "' . self::BODEGA . '" y hay ' . $bodegas->count() . '.');

            return null;
        }
        $bodega = $bodegas->first();
        if ($bodega === null || $bodega->solo_consulta) {
            $this->error('La bodega "' . self::BODEGA . '" es solo_consulta: no se escribe stock en ella.');

            return null;
        }

        if ($bodega->excluye_disponible) {
            $this->warn('AVISO: "' . self::BODEGA . '" está marcada excluye_disponible: al sembrarla, el "Disponible" de los productos sembrados bajará de inmediato (se resta lo que hay en bodegas excluidas).');
        }

        return (int) $bodega->id;
    }

    /**
     * @param array{cp: string, stock: string, legacy: string} $t
     * @return array<string, mixed>
     */
    private function analizar(array $t, int $bodegaId): array
    {
        $p = $this->predicados($t);
        $base = "FROM {$t['cp']} c WHERE {$p['ligado']}";
        $conStock = "{$base} AND c.stock_quantity IS NOT NULL AND c.stock_quantity <> 0";
        $seleccion = "{$conStock} AND {$p['sinFila']} AND {$p['activo']}";

        $s = DB::selectOne(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(c.stock_quantity) FILTER (WHERE c.stock_quantity > 0), 0) AS positivas,
                    COALESCE(SUM(c.stock_quantity) FILTER (WHERE c.stock_quantity < 0), 0) AS negativas,
                    COUNT(*) FILTER (WHERE c.stock_quantity < 0) AS n_negativos,
                    COALESCE(SUM(c.stock_quantity), 0) AS suma,
                    md5(COALESCE(string_agg(c.id::text, ',' ORDER BY c.id), '')) AS huella
             {$seleccion}"
        );
        // Omitidos por la decisión de M1 (solo entre los que de otro modo se sembrarían): inactivos, y descatalogados que siguen activos (sin doble conteo).
        $sinActivo = "{$conStock} AND {$p['sinFila']}";
        $inactivos = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(c.stock_quantity), 0) AS u {$sinActivo} AND NOT c.is_active");
        $descatalogados = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(c.stock_quantity), 0) AS u {$sinActivo} AND c.is_active AND NOT ({$p['activo']})");
        // B5: omitidos por "ya tiene fila" cuyo saldo por bodega no cuadra con stock_quantity (se reporta, no se toca).
        $descuadre = DB::selectOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(c.stock_quantity - (SELECT COALESCE(SUM(s.quantity), 0) FROM {$t['stock']} s WHERE s.catalog_product_id = c.id)), 0) AS dif
             {$conStock} AND NOT ({$p['sinFila']})
             AND (SELECT COALESCE(SUM(s.quantity), 0) FROM {$t['stock']} s WHERE s.catalog_product_id = c.id) <> c.stock_quantity",
        );

        return [
            'ligados'         => (int) DB::selectOne("SELECT COUNT(*) AS c {$base}")->c,
            'sembrar'         => (int) $s->n,
            'positivas'       => (int) $s->positivas,
            'negativas'       => (int) $s->negativas,
            'n_negativos'     => (int) $s->n_negativos,
            'suma'            => (int) $s->suma,
            'huella'          => (string) $s->huella,
            'omitidos_fila'   => (int) DB::selectOne("SELECT COUNT(*) AS c {$conStock} AND NOT ({$p['sinFila']})")->c,
            'omitidos_inactivos'       => (int) $inactivos->n,
            'omitidos_inactivos_u'     => (int) $inactivos->u,
            'omitidos_descatalogados'  => (int) $descatalogados->n,
            'omitidos_descatalogados_u' => (int) $descatalogados->u,
            'descuadre_n'     => (int) $descuadre->n,
            'descuadre_dif'   => (int) $descuadre->dif,
            'omitidos_cero'   => (int) DB::selectOne("SELECT COUNT(*) AS c {$base} AND (c.stock_quantity IS NULL OR c.stock_quantity = 0)")->c,
            'filas_central'   => (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$t['stock']} WHERE warehouse_id = ?", [$bodegaId])->c,
            'seleccion_sql'   => $seleccion,
        ];
    }

    /**
     * Predicados de la selección (se repiten en el INSERT mismo: defensa en profundidad).
     *
     * @param array{cp: string, stock: string, legacy: string} $t
     * @return array{ligado: string, sinFila: string, activo: string}
     */
    private function predicados(array $t): array
    {
        return [
            'ligado'  => "EXISTS (SELECT 1 FROM {$t['legacy']} lp WHERE lp.catalog_product_id = c.id)",
            'sinFila' => "NOT EXISTS (SELECT 1 FROM {$t['stock']} s WHERE s.catalog_product_id = c.id)",
            'activo'  => "c.is_active = true AND NOT EXISTS (SELECT 1 FROM {$t['legacy']} ld WHERE ld.catalog_product_id = c.id AND ld.descatalogado = true)",
        ];
    }

    /** @param array<string, mixed> $plan */
    private function reportar(array $plan): void
    {
        $this->info('legacy:backfill-stock-bodega-central — ' . ($this->option('apply') ? 'APLICAR' : 'DRY-RUN'));
        if ($plan['descuadre_n'] > 0) {
            $this->warn("AVISO: {$plan['descuadre_n']} productos omitidos por ya tener fila por bodega tienen un saldo por bodega que NO cuadra con stock_quantity (diferencia total {$plan['descuadre_dif']} u.): no se tocan; revisarlos con Bodega.");
        }
        $this->table(['Medida', 'Valor'], [
            ['Productos ligados a ICG (legacy.productos)', $plan['ligados']],
            ['A SEMBRAR en ' . self::BODEGA . ' (stock <> 0, sin ninguna fila por bodega)', $plan['sembrar']],
            ['  unidades positivas', $plan['positivas']],
            ['  unidades negativas (se reflejan tal cual)', $plan['negativas'] . " en {$plan['n_negativos']} productos"],
            ['  Σ filas nuevas = Σ stock_quantity de los seleccionados', $plan['suma']],
            ['Omitidos: ya tienen fila en alguna bodega', $plan['omitidos_fila']],
            ['Omitidos: INACTIVOS (is_active = false) — no se siembran', "{$plan['omitidos_inactivos']} ({$plan['omitidos_inactivos_u']} u.)"],
            ['Omitidos: DESCATALOGADOS en ICG (y activos) — no se siembran', "{$plan['omitidos_descatalogados']} ({$plan['omitidos_descatalogados_u']} u.)"],
            ['Omitidos: stock_quantity nulo o 0', $plan['omitidos_cero']],
            ['Filas actuales de ' . self::BODEGA, $plan['filas_central']],
        ]);
    }

    /**
     * @param array{cp: string, stock: string, legacy: string} $t
     * @param array<string, mixed> $plan
     * @return array<string, int>
     */
    private function aplicar(array $t, array $plan, int $bodegaId, string $slug, int $lote): array
    {
        // El plan se recalcula dentro de la transacción: si la selección cambió desde el dry-run, se aborta.
        $actual = $this->analizar($t, $bodegaId);
        if ($actual['huella'] !== $plan['huella'] || $actual['suma'] !== $plan['suma']) {
            throw new RuntimeException('la selección cambió entre el análisis y la escritura (huella distinta): repite el dry-run.');
        }

        $ids = array_values(array_map(static fn ($r): int => (int) $r->id, DB::select("SELECT c.id {$plan['seleccion_sql']} ORDER BY c.id")));
        // Verificación ACOTADA a los ids seleccionados: un movimiento normal de Bodega sobre cualquier otro producto durante la corrida no provoca un aborto falso.
        $antes = $this->estado($t, $bodegaId, $ids);
        if ($antes['filas_ids'] !== 0) {
            throw new RuntimeException("{$antes['filas_ids']} filas por bodega ya existen para los productos seleccionados (cambió la selección).");
        }

        if (self::$antesDeInsertar !== null) {
            (self::$antesDeInsertar)();
        }

        $p = $this->predicados($t);
        $insertadas = 0;
        foreach (array_chunk($ids, max(1, $lote)) as $pedazo) {
            $lista = implode(',', $pedazo);
            // Los predicados de la selección se repiten en el INSERT mismo (si algo cambió, el lote inserta menos y se aborta).
            $n = DB::affectingStatement(
                "INSERT INTO {$t['stock']} (catalog_product_id, warehouse_id, quantity, created_at, updated_at)
                 SELECT c.id, {$bodegaId}, c.stock_quantity, now(), now() FROM {$t['cp']} c
                 WHERE c.id IN ({$lista}) AND {$p['ligado']} AND c.stock_quantity IS NOT NULL AND c.stock_quantity <> 0 AND {$p['sinFila']} AND {$p['activo']}
                 ON CONFLICT (catalog_product_id, warehouse_id) DO NOTHING"
            );
            if ($n !== count($pedazo)) {
                throw new RuntimeException("el lote insertó {$n} de " . count($pedazo) . ' filas (una fila apareció o el producto cambió concurrentemente).');
            }
            $insertadas += $n;
        }

        if (self::$antesDeVerificar !== null) {
            (self::$antesDeVerificar)();
        }

        $despues = $this->estado($t, $bodegaId, $ids);
        $fallos = [];
        if ($insertadas !== $plan['sembrar']) {
            $fallos[] = "insertadas {$insertadas}, esperadas {$plan['sembrar']}";
        }
        if ($despues['filas_ids'] !== $plan['sembrar'] || $despues['filas_central_ids'] !== $plan['sembrar']) {
            $fallos[] = "filas de los productos seleccionados: {$despues['filas_ids']} ({$despues['filas_central_ids']} en Bodega Central), esperadas {$plan['sembrar']}";
        }
        if ($despues['suma_ids'] !== $plan['suma']) {
            $fallos[] = "Σ de las filas nuevas {$despues['suma_ids']} != Σ stock_quantity {$plan['suma']}";
        }
        if ($despues['huella_stock_quantity'] !== $antes['huella_stock_quantity']) {
            $fallos[] = 'algún stock_quantity de los productos seleccionados cambió';
        }
        if ($fallos !== []) {
            throw new RuntimeException('verificación fallida: ' . implode('; ', $fallos));
        }

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL,
            'entity_type' => 'product_warehouse_stock',
            'metadata'    => [
                'operador'        => (string) $this->option('operador'),
                'usuario_so'      => get_current_user(),
                'host'            => (string) gethostname(),
                'entorno'         => (string) config('app.env'),
                'tenant'          => $slug,
                'filas_sembradas' => $insertadas,
                'unidades_positivas' => $plan['positivas'],
                'unidades_negativas' => $plan['negativas'],
                'productos_negativos' => $plan['n_negativos'],
                'omitidos_con_fila'  => $plan['omitidos_fila'],
                'omitidos_cero_o_nulo' => $plan['omitidos_cero'],
                'huella_seleccion'   => $plan['huella'],
                'omitidos_inactivos' => $plan['omitidos_inactivos'],
                'omitidos_descatalogados' => $plan['omitidos_descatalogados'],
                // Ids sembrados: permiten revertir con seguridad (ver el runbook). Si superan el tope se guarda solo el recorte y la huella.
                'ids_sembrados'      => array_slice($ids, 0, self::MAX_IDS_AUDITORIA),
                'ids_sembrados_truncado' => count($ids) > self::MAX_IDS_AUDITORIA,
            ],
        ]);

        return ['filas' => $insertadas];
    }

    /**
     * Estado medible antes/después, ACOTADO a los productos seleccionados.
     *
     * @param array{cp: string, stock: string, legacy: string} $t
     * @param list<int> $ids
     * @return array{filas_ids: int, filas_central_ids: int, suma_ids: int, huella_stock_quantity: string}
     */
    private function estado(array $t, int $bodegaId, array $ids): array
    {
        $lista = $ids === [] ? '-1' : implode(',', $ids);
        $filas = DB::selectOne(
            "SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE warehouse_id = ?) AS en_central, COALESCE(SUM(quantity), 0) AS suma
             FROM {$t['stock']} WHERE catalog_product_id IN ({$lista})",
            [$bodegaId],
        );

        return [
            'filas_ids'             => (int) $filas->n,
            'filas_central_ids'     => (int) $filas->en_central,
            'suma_ids'              => (int) $filas->suma,
            'huella_stock_quantity' => (string) DB::selectOne(
                "SELECT md5(COALESCE(string_agg(id::text || ':' || COALESCE(stock_quantity::text, 'N'), ',' ORDER BY id), '')) AS h FROM {$t['cp']} WHERE id IN ({$lista})"
            )->h,
        ];
    }
}
