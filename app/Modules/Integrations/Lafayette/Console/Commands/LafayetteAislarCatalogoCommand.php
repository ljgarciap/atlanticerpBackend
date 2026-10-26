<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\ZonaLibreCatalogoService;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * SCRUM-990 — limpieza ÚNICA (destructiva) de los datos de Lafayette que el sincronizador viejo dejó mezclados con los de AtlanticERP.
 * Diseño: docs/architecture/scrum990-aislar-lafayette-diseno.md, sección 6. Se retira del repositorio tras limpiar los 3 entornos.
 *
 * CLASIFICACIÓN por evidencia de auditoría (no por ausencia de enlace con ICG): de los productos con `external_source = lafayette_lighting`,
 *  - ICG: enlazados a `legacy.productos` => se RESTAURAN.
 *  - PREEXISTENTES no-ICG: tienen un evento de ENLACE (`ventas_diseno.catalog_product.updated` que puso `external_source`) pero no están en ICG (p. ej. un
 *    producto de AtlanticERP creado a mano que el loader viejo "vinculó") => se RESTAURAN igual que los ICG, jamás se borran; el dry-run los reporta aparte.
 *  - PUROS: sin evento de enlace ni vínculo ICG (los creó el sincronizador). Sin ninguna referencia en tablas con `catalog_product_id` => se BORRAN.
 *    PUROS REFERENCIADOS (cotizaciones, pedidos, stock en otras bodegas... decisión de Luis, 2026-10-02) => se PROMUEVEN: no se borran, solo pierden la marca
 *    de Lafayette (`external_source`/`external_synced_at` a NULL) y conservan su dato (nombre, precio, proveedor, referencia); los documentos quedan intactos.
 *    Los puros (borrables o promovibles) que además no tienen NINGÚN evento `created` con external_source Lafayette (sin evidencia) bloquean salvo `--permitir-puros-sin-evidencia`.
 *
 * RESTAURACIÓN desde `audit_logs`: un producto es restaurable solo si su evento de enlace trae `provider_id` en `old_values` (el enlace siempre lo cambia;
 * un `old_values` vacío NO vale). El valor original de cada campo es el MÁS ANTIGUO entre los eventos del loader (user_id nulo) desde el enlace — no solo
 * el primer evento, porque `old_values` es un diff y un campo que el primer enlace no cambió pudo cambiarlo una corrida posterior. Si un USUARIO editó
 * un campo después del enlace, ese campo NO se pisa: se conserva y se reporta. Nunca se desmarca un producto en silencio.
 *
 * Todo en UNA transacción, con las tablas afectadas bloqueadas (SHARE ROW EXCLUSIVE) y el plan RECALCULADO dentro; si su huella difiere de la que
 * se confirmó, aborta (TOCTOU). Respaldo en tablas `*_bak_scrum990` (también de las filas hijas que caen en CASCADE), restauración, borrado del stock de
 * Zona Libre y de los puros, anulación del historial de prueba contra la bodega (solo tablas de una lista explícita, solo filas que ÚNICAMENTE tocan Zona
 * Libre, con precondiciones), verificación (con comprobaciones independientes del `old_values` usado para restaurar) y el evento de auditoría resumen
 * DENTRO de la transacción: si cualquier paso falla, ROLLBACK de todo.
 *
 * `--dry-run` es el modo POR DEFECTO (solo lecturas). `--apply` exige escribir `APLICAR <entorno> <tenant>`; `--yes` solo en una lista BLANCA de entornos
 * (local, testing) leyendo `config('app.env')` (el APP_ENV real: `--env=` no lo cambia). Fuera de local/testing exige el rol de migración
 * (`DB_MIGRATE_*`, distinto del rol de la aplicación) como usuario de la conexión.
 *
 * @phpstan-type Tablas array{slug: string, cp: string, stock: string, wh: string, audit: string, lafprod: string, providers: string, legacy: string|null}
 */
final class LafayetteAislarCatalogoCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    private const EXTERNAL_SOURCE = 'lafayette_lighting';

    private const ZONA_LIBRE = 'Bodega Zona Libre';

    private const BAK = '_bak_scrum990';

    private const ACCION_ACTUALIZADO = 'ventas_diseno.catalog_product.updated';

    private const ACCION_CREADO = 'ventas_diseno.catalog_product.created';

    /** Entornos en los que se acepta `--yes` y no se exige el rol de migración. Lista BLANCA, leída de `config('app.env')`. */
    private const ENTORNOS_SIN_CONFIRMACION = ['local', 'testing'];

    /** Máximo de ids que se listan en el reporte (el total siempre se imprime). */
    private const MAX_LISTADOS = 200;

    /** Campos de `catalog_products` que el loader viejo sobrescribía (`CatalogProductLoader::updateExisting`) => cast SQL de asignación. */
    private const RESTORE_FIELDS = [
        'factory_reference' => null,
        'provider_id'       => 'bigint',
        'name'              => null,
        'brand'             => null,
        'photo_url'         => null,
        'category'          => null,
        'price_full'        => 'numeric',
    ];

    /**
     * Tablas cuyas filas contra la bodega Zona Libre son HISTORIAL DE PRUEBA y se anulan (decisión de Luis, 2026-09-30). CUALQUIER OTRA tabla con filas
     * contra esa bodega (ubicaciones, mapeos, líneas de ajuste...) es configuración o trazabilidad distinta: no se toca y bloquea la limpieza.
     */
    private const HISTORIAL_TABLAS = ['inventory_movements', 'purchase_order_receipt_lines', 'customer_returns', 'general_count_requests'];

    /**
     * Tablas con filas contra Zona Libre que NO se anulan pero que, con `--conservar-historial-zona-libre`, se aceptan como historial CONSERVABLE (decisión de
     * Luis, 2026-10-02: la bodega se conserva sin stock, así que esas referencias no se rompen; no se tocan). Sin la bandera siguen rechazando. CUALQUIER OTRA
     * tabla con filas contra la bodega es desconocida y bloquea siempre.
     */
    private const HISTORIAL_CONSERVABLE = ['order_items', 'adjustment_request_lines', 'relocation_requests', 'warehouse_locations'];

    /**
     * Solo para pruebas (TOCTOU): se invoca justo DESPUÉS de la confirmación y ANTES de `aplicar()`, para simular que los datos cambian en esa ventana.
     * Nunca se asigna en código de producción.
     */
    public static ?\Closure $despuesDeConfirmar = null;

    /** @var string */
    protected $signature = 'lafayette:aislar-catalogo
        {--tenant=atlantic : Slug del tenant}
        {--apply : Ejecuta la limpieza. Sin esta opción es un dry-run que solo lee}
        {--yes : Omite la confirmación interactiva. SOLO en local y testing (lista blanca sobre APP_ENV); en cualquier otro entorno se rechaza}
        {--operador= : Quién ejecuta (queda en el evento de auditoría junto con el usuario del SO y el host). OBLIGATORIO con --apply}
        {--lock-timeout=5 : Segundos máximos de espera por los bloqueos de tabla antes de abortar con rollback (SET LOCAL lock_timeout)}
        {--statement-timeout=600 : Segundos máximos por sentencia dentro de la limpieza (SET LOCAL statement_timeout)}
        {--conservar-historial-zona-libre : No anula el historial de prueba (movimientos, recepciones, devoluciones, conteos) contra la bodega Zona Libre}
        {--permitir-icg-sin-auditoria : Permite continuar con productos restaurables SIN evento válido si NINGUNO está referenciado por documentos (solo se les quita la marca y el proveedor de Lafayette)}
        {--permitir-puros-sin-evidencia : Permite borrar puros que no tienen ningún evento de creación en audit_logs}';

    /** @var string */
    protected $description = 'SCRUM-990 (una sola vez) — separa los productos de Lafayette del catálogo de AtlanticERP: respalda, restaura los ICG y preexistentes, borra los puros y su stock en Zona Libre';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('yes') && ! $this->entornoSinConfirmacion()) {
            $this->error('--yes no se acepta en el entorno "' . $this->entorno() . '": solo en ' . implode(' y ', self::ENTORNOS_SIN_CONFIRMACION) . '. La confirmación debe ser interactiva.');

            return self::FAILURE;
        }

        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría (quién ejecutó la limpieza).');

            return self::FAILURE;
        }
        foreach (['lock-timeout', 'statement-timeout'] as $opcion) {
            if (filter_var($this->option($opcion), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]) === false) {
                $this->error("--{$opcion} debe ser un entero de segundos entre 1 y 86400.");

                return self::FAILURE;
            }
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /** SQLSTATE de Postgres tras una excepción de PDO/Query ('' si no hay). */
    private function sqlState(Throwable $e): string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException && is_array($x->errorInfo ?? null) && isset($x->errorInfo[0])) {
                return (string) $x->errorInfo[0];
            }
        }

        return '';
    }

    /** APP_ENV real (`config('app.env')`): a diferencia de `app()->environment()`, no lo cambia `--env=` en la línea de comandos. */
    private function entorno(): string
    {
        return (string) config('app.env');
    }

    private function entornoSinConfirmacion(): bool
    {
        return in_array($this->entorno(), self::ENTORNOS_SIN_CONFIRMACION, true);
    }

    private function ejecutar(string $slug, bool $apply): int
    {
        $t = $this->tablas($slug);
        $conservar = (bool) $this->option('conservar-historial-zona-libre');

        $zlId = $this->resolverZonaLibre();
        if ($zlId === null) {
            return self::FAILURE;
        }

        $plan = $this->analizar($t, $zlId, $conservar);
        $this->reportar($plan);

        if ($plan['nada_que_hacer']) {
            $this->info('Nada que hacer: el catálogo ya está aislado (sin productos de origen Lafayette ni stock/historial en Zona Libre).');

            return self::SUCCESS;
        }

        $bloqueos = $this->bloqueos($plan);
        if ($bloqueos !== []) {
            $this->newLine();
            $this->error('La limpieza NO puede aplicarse. Motivos:');
            foreach ($bloqueos as $motivo) {
                $this->line("  - {$motivo}");
            }

            return self::FAILURE;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Huella del plan: <info>' . $plan['huella'] . '</info> (--apply recalcula el plan bajo bloqueo y aborta si cambió).');
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply (pide confirmación escrita). Ventana de mantenimiento recomendada: --apply bloquea las tablas afectadas.');

            return self::SUCCESS;
        }

        $errorRol = $this->errorRolMigracion();
        if ($errorRol !== null) {
            $this->error($errorRol);

            return self::FAILURE;
        }

        if (! $this->confirmar($slug, $plan['huella'])) {
            $this->warn('Confirmación no recibida: no se aplicó nada.');

            return self::FAILURE;
        }

        if (self::$despuesDeConfirmar !== null) {
            (self::$despuesDeConfirmar)();
        }

        try {
            $resultado = $this->aplicar($t, $plan, $zlId, $conservar);
        } catch (Throwable $e) {
            $motivo = in_array($this->sqlState($e), ['55P03', '57014'], true)
                ? 'Se agotó el tiempo de espera de bloqueo o de sentencia (--lock-timeout=' . (int) $this->option('lock-timeout') . 's, --statement-timeout=' . (int) $this->option('statement-timeout')
                    . 's): hay transacciones concurrentes sobre las tablas. Pausa Horizon, el sync ICG y lafayette:sync-productos (o usa la ventana de mantenimiento) y reintenta.'
                : $this->mensajeSeguro($e);
            $this->error('ROLLBACK: la limpieza NO se aplicó. ' . $motivo);

            return self::FAILURE;
        }

        // Los cachés no son transaccionales: se invalidan DESPUÉS del commit. (No existen otros cachés de catálogo: solo el resumen de Zona Libre.)
        ZonaLibreCatalogoService::forgetResumen();

        $this->newLine();
        $this->info('Limpieza aplicada y verificada.');
        $this->table(['Medida', 'Valor'], array_map(
            static fn (int|string $k, mixed $v): array => [(string) $k, is_scalar($v) ? (string) $v : json_encode($v)],
            array_keys($resultado),
            array_values($resultado),
        ));
        $this->comment('Siguiente paso del runbook: correr legacy:sync-incremental-productos y la verificación funcional (Pre-QA). Los respaldos *' . self::BAK . ' se conservan hasta la aceptación.');

        return self::SUCCESS;
    }

    /** Exactamente UNA bodega con ese nombre y marcada `solo_consulta`; si no, aborta (nunca borra stock de una bodega que podría ser operativa). */
    private function resolverZonaLibre(): ?int
    {
        $bodegas = Warehouse::query()->where('name', self::ZONA_LIBRE)->get();
        if ($bodegas->count() !== 1) {
            $this->error('Se esperaba exactamente UNA bodega "' . self::ZONA_LIBRE . '" y hay ' . $bodegas->count() . '.');

            return null;
        }

        $bodega = $bodegas->first();
        if ($bodega === null || ! $bodega->solo_consulta) {
            $this->error('La bodega "' . self::ZONA_LIBRE . '" no está marcada solo_consulta: no se toca su stock.');

            return null;
        }

        return (int) $bodega->id;
    }

    /** Fuera de local/testing la conexión DEBE ser el rol de migración (`DB_MIGRATE_*`), distinto del rol de la aplicación. */
    private function errorRolMigracion(): ?string
    {
        if ($this->entornoSinConfirmacion()) {
            return null;
        }

        $migrate = (string) config('lafayette.migrate_username', '');
        if ($migrate === '' || ! (bool) config('lafayette.migrate_password_definida', false)) {
            return 'DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD no están definidos en este entorno: la limpieza necesita el rol de migración (ver runbook).';
        }

        $actual = (string) (DB::selectOne('SELECT current_user AS u')->u ?? '');
        $rolApp = (string) config('lafayette.app_role', 'atlanticerp_app');
        if ($actual === $rolApp) {
            return "La conexión usa el rol de la aplicación ({$actual}): ejecuta con DB_USERNAME/DB_PASSWORD de DB_MIGRATE_* (ver runbook).";
        }
        if ($actual !== $migrate) {
            return "La conexión usa el rol \"{$actual}\", distinto de DB_MIGRATE_USERNAME (\"{$migrate}\"): ejecuta con las credenciales de migración (ver runbook).";
        }

        return null;
    }

    private function confirmar(string $slug, string $huella): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }

        $esperado = 'APLICAR ' . $this->entorno() . ' ' . $slug;
        $this->newLine();
        $this->warn('Operación DESTRUCTIVA: borra filas de catalog_products y product_warehouse_stock (con respaldo en tablas *' . self::BAK . ').');
        $this->line("Huella del plan que vas a confirmar: {$huella}");

        return trim((string) $this->ask("Para continuar escribe exactamente: {$esperado}")) === $esperado;
    }

    // ── Nombres de tablas y fragmentos SQL ───────────────────────────────────────────────────────────────────

    /** @return Tablas */
    private function tablas(string $slug): array
    {
        $legacy = "{$slug}_legacy.productos";

        return [
            'slug'      => $slug,
            'cp'        => (new CatalogProduct())->getTable(),
            'stock'     => (new ProductWarehouseStock())->getTable(),
            'wh'        => (new Warehouse())->getTable(),
            'audit'     => (new AuditLog())->getTable(),
            'lafprod'   => (new LafayetteProducto())->getTable(),
            'providers' => "{$slug}_compras.providers",
            'legacy'    => Schema::hasTable($legacy) ? $legacy : null,
        ];
    }

    /**
     * CTEs de auditoría (los datos de las tablas de auditoría son la fuente de la restauración). `entity_id` se compara como TEXTO en los joins
     * para no depender del orden de evaluación de un cast sobre filas de otras entidades.
     *  - enlace: primer evento `updated` que puso `external_source` = Lafayette, por producto.
     *  - creados: productos con evento `created` con `external_source` = Lafayette (los creó el sincronizador).
     *  - eventos_loader: eventos `updated` del producto desde el enlace, sin usuario (los hace el sincronizador por consola).
     *  - original: valor MÁS ANTIGUO por clave entre esos eventos (`jsonb_object_agg` con orden DESC: gana la última asignación = la más antigua).
     *  - ediciones: campos que un USUARIO cambió después del enlace.
     *
     * @param Tablas $t
     */
    private function cte(array $t): string
    {
        $audit = $t['audit'];
        $upd = self::ACCION_ACTUALIZADO;
        $crt = self::ACCION_CREADO;
        $src = self::EXTERNAL_SOURCE;
        $campos = "'" . implode("','", array_keys(self::RESTORE_FIELDS)) . "'";

        return "WITH enlace AS (
                SELECT entity_id::bigint AS id, MIN(id) AS eid
                FROM {$audit}
                WHERE action = '{$upd}' AND entity_type = 'catalog_product' AND entity_id ~ '^[0-9]+\$'
                  AND new_values->>'external_source' = '{$src}'
                GROUP BY entity_id
            ), creados AS (
                SELECT DISTINCT entity_id::bigint AS id
                FROM {$audit}
                WHERE action = '{$crt}' AND entity_type = 'catalog_product' AND entity_id ~ '^[0-9]+\$'
                  AND new_values->>'external_source' = '{$src}'
            ), eventos_loader AS (
                SELECT e.id AS id, a.id AS eid, a.old_values AS ov
                FROM {$audit} a JOIN enlace e ON a.entity_id = e.id::text
                WHERE a.action = '{$upd}' AND a.entity_type = 'catalog_product' AND a.id >= e.eid AND a.user_id IS NULL
            ), original AS (
                SELECT l.id, jsonb_object_agg(kv.key, kv.value ORDER BY l.eid DESC) AS ov
                FROM eventos_loader l, jsonb_each(COALESCE(l.ov, '{}'::jsonb)) AS kv
                WHERE kv.key IN ({$campos})
                GROUP BY l.id
            ), ediciones AS (
                SELECT e.id AS id, array_agg(DISTINCT k.key) AS keys
                FROM {$audit} a JOIN enlace e ON a.entity_id = e.id::text,
                     jsonb_object_keys(COALESCE(a.new_values, '{}'::jsonb)) AS k(key)
                WHERE a.action = '{$upd}' AND a.entity_type = 'catalog_product' AND a.id > e.eid AND a.user_id IS NOT NULL
                  AND k.key IN ({$campos})
                GROUP BY e.id
            )";
    }

    /**
     * Conjuntos de productos (subconsultas que usan los CTE de {@see cte()}).
     *
     * @param Tablas $t
     * @param list<array{schema: string, tabla: string, columna: string}> $refs tablas con `catalog_product_id` (sin el stock) que dividen los puros
     * @return array<string, string>
     */
    private function conjuntos(array $t, array $refs = [], int $zlId = 0): array
    {
        $origen = "SELECT id FROM {$t['cp']} WHERE external_source = '" . self::EXTERNAL_SOURCE . "'";
        $linked = $this->sqlEnlazados($t);
        $valido = "SELECT id FROM original WHERE jsonb_exists(ov, 'provider_id')";
        $restaurables = "{$origen} AND (id IN ({$linked}) OR id IN (SELECT id FROM enlace))";
        $puros = "{$origen} AND id NOT IN ({$linked}) AND id NOT IN (SELECT id FROM enlace)";

        // Referenciado = alguna fila en cualquier tabla con `catalog_product_id` o stock en una bodega que NO es Zona Libre (el de Zona Libre se borra siempre).
        $usos = ["SELECT catalog_product_id AS pid FROM {$t['stock']} WHERE warehouse_id <> {$zlId} AND catalog_product_id IS NOT NULL"];
        foreach ($refs as $r) {
            $usos[] = "SELECT {$r['columna']} AS pid FROM {$r['schema']}.{$r['tabla']} WHERE {$r['columna']} IS NOT NULL";
        }
        $referenciados = implode(' UNION ', $usos);

        return [
            'origen'        => $origen,
            'icg'           => "{$origen} AND id IN ({$linked})",
            'preexistentes' => "{$origen} AND id NOT IN ({$linked}) AND id IN (SELECT id FROM enlace)",
            'puros'         => $puros,
            'puros_borrar'  => "{$puros} AND id NOT IN ({$referenciados})",
            'puros_promover' => "{$puros} AND id IN ({$referenciados})",
            'puros_con'     => "{$puros} AND id IN (SELECT id FROM creados)",
            'puros_sin'     => "{$puros} AND id NOT IN (SELECT id FROM creados)",
            'restaurables'  => $restaurables,
            'validos'       => "{$restaurables} AND id IN ({$valido})",
            'sin_valido'    => "{$restaurables} AND id NOT IN ({$valido})",
        ];
    }

    /** @param Tablas $t */
    private function sqlEnlazados(array $t): string
    {
        return $t['legacy'] !== null
            ? "SELECT catalog_product_id FROM {$t['legacy']} WHERE catalog_product_id IS NOT NULL"
            : 'SELECT NULL::bigint WHERE false';
    }

    /** @param array<string, mixed> $bindings */
    private function contar(string $cte, string $sql, array $bindings = []): int
    {
        return (int) (DB::selectOne("SELECT COUNT(*) AS c FROM ({$cte} {$sql}) q", $bindings)->c ?? 0);
    }

    private function md5Ids(string $cte, string $sql): string
    {
        return (string) (DB::selectOne("SELECT md5(COALESCE(string_agg(id::text, ',' ORDER BY id), '')) AS h FROM ({$cte} {$sql}) q")->h ?? '');
    }

    /** @return list<int> */
    private function listarIds(string $cte, string $sql, int $limite = self::MAX_LISTADOS): array
    {
        return array_values(array_map(
            static fn ($r): int => (int) $r->id,
            DB::select("{$cte} {$sql} ORDER BY id LIMIT {$limite}"),
        ));
    }

    /**
     * Filas que TOCAN la bodega en alguna de sus columnas.
     *
     * @param list<string> $columnas
     */
    public static function whereTocaBodega(array $columnas, int $bodegaId): string
    {
        return '(' . implode(' OR ', array_map(static fn (string $c): string => "{$c} = {$bodegaId}", $columnas)) . ')';
    }

    /**
     * Filas que SOLO tocan esa bodega: toda columna de bodega no nula apunta a ella (y al menos una lo hace). Una fila que además tenga OTRA bodega
     * (operativa) en otra columna queda fuera: anularla descuadraría el kardex de esa bodega.
     *
     * @param list<string> $columnas
     */
    public static function whereSoloBodega(array $columnas, int $bodegaId): string
    {
        $todas = implode(' AND ', array_map(static fn (string $c): string => "({$c} IS NULL OR {$c} = {$bodegaId})", $columnas));

        return "({$todas}) AND " . self::whereTocaBodega($columnas, $bodegaId);
    }

    // ── Descubrimiento de referencias ────────────────────────────────────────────────────────────────────────

    /**
     * Columnas (FK o por nombre) que apuntan a `$target`, excluyendo tablas de respaldo y las indicadas.
     *
     * @param list<string> $exclude tablas calificadas a excluir
     * @return list<array{schema: string, tabla: string, columna: string}>
     */
    private function columnasQueReferencian(string $target, array $exclude, string $slug): array
    {
        $found = [];
        foreach ($this->fks($target) as $fk) {
            $found["{$fk['schema']}.{$fk['tabla']}.{$fk['columna']}"] = $fk;
        }

        // Sin FK declarada, por nombre de columna (p. ej. tablas de `legacy`): mismo criterio conservador.
        $byName = DB::select(
            "SELECT table_schema AS esquema, table_name AS tabla, column_name AS columna FROM information_schema.columns
             WHERE column_name = 'catalog_product_id' AND table_schema LIKE ?",
            [$slug . '\\_%'],
        );
        foreach ($byName as $c) {
            $found["{$c->esquema}.{$c->tabla}.{$c->columna}"] = ['schema' => (string) $c->esquema, 'tabla' => (string) $c->tabla, 'columna' => (string) $c->columna];
        }

        return array_values(array_filter(
            $found,
            static fn (array $r): bool => ! str_ends_with($r['tabla'], self::BAK) && ! in_array("{$r['schema']}.{$r['tabla']}", $exclude, true),
        ));
    }

    /**
     * FKs que apuntan a `$target`; con `$soloCascade` solo las `ON DELETE CASCADE` (las filas hijas que caen al borrar el padre).
     *
     * @return list<array{schema: string, tabla: string, columna: string}>
     */
    private function fks(string $target, bool $soloCascade = false): array
    {
        $rows = DB::select(
            "SELECT n.nspname AS esquema, c.relname AS tabla, a.attname AS columna
             FROM pg_constraint con
             JOIN pg_class c ON c.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY (con.conkey)
             WHERE con.contype = 'f' AND con.confrelid = ?::regclass" . ($soloCascade ? " AND con.confdeltype = 'c'" : ''),
            [$target],
        );

        return array_values(array_map(static fn ($r): array => ['schema' => (string) $r->esquema, 'tabla' => (string) $r->tabla, 'columna' => (string) $r->columna], $rows));
    }

    /**
     * Tablas con filas contra una bodega (FK a `warehouses.id`), salvo el stock y los respaldos.
     *
     * @param Tablas $t
     * @return array<string, list<string>> tabla calificada => columnas
     */
    private function historialContraBodega(array $t): array
    {
        $tablas = [];
        foreach ($this->fks($t['wh']) as $r) {
            $full = "{$r['schema']}.{$r['tabla']}";
            if ($full === $t['stock'] || str_ends_with($r['tabla'], self::BAK)) {
                continue;
            }
            $tablas[$full][] = $r['columna'];
        }

        return $tablas;
    }

    /**
     * @param Tablas $t
     * @return list<string>
     */
    private function bakExistentes(array $t): array
    {
        $rows = DB::select(
            "SELECT table_schema || '.' || table_name AS t FROM information_schema.tables WHERE table_name LIKE ? AND table_schema LIKE ?",
            ['%' . self::BAK, $t['slug'] . '\\_%'],
        );

        return array_values(array_map(static fn ($r): string => (string) $r->t, $rows));
    }

    // ── Análisis (solo lectura) ──────────────────────────────────────────────────────────────────────────────

    /**
     * @param Tablas $t
     * @return array<string, mixed>
     */
    private function analizar(array $t, int $zlId, bool $conservarHistorial): array
    {
        $cp = $t['cp'];
        $cte = $this->cte($t);
        $refs = $this->columnasQueReferencian($cp, [$t['stock']], $t['slug']);
        $s = $this->conjuntos($t, $refs, $zlId);
        $c = fn (string $sql, array $b = []): int => $this->contar($cte, $sql, $b);

        $zlStock = DB::selectOne("SELECT COUNT(*) AS filas, COALESCE(SUM(quantity), 0) AS unidades FROM {$t['stock']} WHERE warehouse_id = ?", [$zlId]);
        $otras = DB::selectOne("SELECT COUNT(*) AS filas, COALESCE(SUM(quantity), 0) AS unidades FROM {$t['stock']} WHERE warehouse_id <> ?", [$zlId]);

        // Puros referenciados: ya no bloquean, se PROMUEVEN (pierden la marca de Lafayette y se conservan). Se cuentan sus referencias para verificar que no cambian.
        $bloqueosPuros = [];
        $promoverRefs = [];
        $usosPorId = [];
        $enOtras = DB::select("{$cte} SELECT catalog_product_id AS id, COUNT(*) AS n FROM {$t['stock']} WHERE warehouse_id <> {$zlId} AND catalog_product_id IN ({$s['puros_promover']}) GROUP BY 1");
        $promoverRefs[$t['stock'] . ' (otras bodegas)'] = 0;
        foreach ($enOtras as $r) {
            $usosPorId[(int) $r->id][$t['stock'] . ' (otras bodegas)'] = (int) $r->n;
            $promoverRefs[$t['stock'] . ' (otras bodegas)'] += (int) $r->n;
        }

        $idsConDocumentos = [];
        $conteoReferencias = [];
        foreach ($refs as $ref) {
            $full = "{$ref['schema']}.{$ref['tabla']}";
            $col = $ref['columna'];
            $conteoReferencias["{$full}.{$col}"] = $c("SELECT 1 FROM {$full}");
            foreach (DB::select("{$cte} SELECT {$col} AS id, COUNT(*) AS n FROM {$full} WHERE {$col} IN ({$s['puros_promover']}) GROUP BY 1") as $r) {
                $usosPorId[(int) $r->id]["{$full}.{$col}"] = (int) $r->n;
                $promoverRefs["{$full}.{$col}"] = ($promoverRefs["{$full}.{$col}"] ?? 0) + (int) $r->n;
            }
            if (! str_contains($ref['schema'], '_legacy')) {
                foreach (DB::select("{$cte} SELECT DISTINCT {$col} AS id FROM {$full} WHERE {$col} IN ({$s['restaurables']})") as $row) {
                    $idsConDocumentos[(int) $row->id] = true;
                }
            }
        }
        $idsConDocumentos = array_keys($idsConDocumentos);
        sort($idsConDocumentos);

        $documentales = $this->detalleDocumentales($t, $cte, $idsConDocumentos);

        $ediciones = [];
        foreach (DB::select("{$cte} SELECT ed.id, array_to_string(ed.keys, ',') AS keys FROM ediciones ed WHERE ed.id IN ({$s['restaurables']}) ORDER BY ed.id LIMIT " . self::MAX_LISTADOS) as $r) {
            $ediciones[] = ['id' => (int) $r->id, 'campos' => (string) $r->keys];
        }

        [$historial, $bloqueosHistorial, $otrasReferenciasBodega, $hijas, $desfases, $conservables] = $this->analizarHistorial($t, $zlId, $conservarHistorial);

        $promover = [];
        ksort($usosPorId);
        if ($usosPorId !== []) {
            foreach (DB::select("SELECT id, reference, name, provider_id FROM {$cp} WHERE id IN (" . implode(',', array_map('intval', array_slice(array_keys($usosPorId), 0, self::MAX_LISTADOS))) . ') ORDER BY id') as $r) {
                $promover[] = ['id' => (int) $r->id, 'reference' => $r->reference, 'name' => $r->name, 'usos' => $usosPorId[(int) $r->id]];
            }
        }
        $promoverRefs = array_filter($promoverRefs, static fn (int $n): bool => $n > 0);

        $origen = $c($s['origen']);
        $huellas = [
            'puros'         => $this->md5Ids($cte, $s['puros']),
            'puros_promover' => $this->md5Ids($cte, $s['puros_promover']),
            'icg'           => $this->md5Ids($cte, $s['icg']),
            'preexistentes' => $this->md5Ids($cte, $s['preexistentes']),
            'stock_zl'      => $this->md5Ids('', "SELECT id FROM {$t['stock']} WHERE warehouse_id = {$zlId}"),
        ];
        foreach ($historial as $tabla => $h) {
            $huellas['historial:' . $tabla] = $this->md5Ids('', "SELECT id FROM {$tabla} WHERE {$h['where']}");
        }

        $plan = [
            'zl_id'                    => $zlId,
            'origen'                   => $origen,
            'puros'                    => $c($s['puros']),
            'puros_borrar'             => $c($s['puros_borrar']),
            'puros_promover'           => $c($s['puros_promover']),
            'promover_detalle'         => $promover,
            'promover_refs'            => $promoverRefs,
            'puros_con_evidencia'      => $c($s['puros_con']),
            'puros_sin_evidencia'      => $c($s['puros_sin']),
            'icg'                      => $c($s['icg']),
            'preexistentes'            => $c($s['preexistentes']),
            'restaurables_validos'     => $c($s['validos']),
            'restaurables_sin_valido'  => $c($s['sin_valido']),
            'icg_documentales'         => $documentales,
            'ediciones_de_usuario'     => $ediciones,
            'zl_stock_filas'           => (int) $zlStock->filas,
            'zl_stock_unidades'        => (int) $zlStock->unidades,
            'otras_stock_filas'        => (int) $otras->filas,
            'otras_stock_unidades'     => (int) $otras->unidades,
            'bloqueos_puros'           => $bloqueosPuros,
            'historial'                => $historial,
            'bloqueos_historial'       => $bloqueosHistorial,
            'otras_referencias_bodega' => $otrasReferenciasBodega,
            'historial_conserva'      => $conservables,
            'hijas_cascade'            => $hijas,
            'desfases'                 => $desfases,
            'conservar_historial'      => $conservarHistorial,
            'lafayette_productos'      => $c("SELECT 1 FROM {$t['lafprod']}"),
            'provider_lafayette'       => DB::selectOne("SELECT id FROM {$t['providers']} WHERE name = 'Lafayette Lighting' LIMIT 1")->id ?? null,
            'cp_total'                 => $c("SELECT 1 FROM {$cp}"),
            'conteo_referencias'       => $conteoReferencias,
            'refs'                     => $refs,
            'bak_existentes'           => $this->bakExistentes($t),
            'huellas'                  => $huellas,
        ];
        $plan['nada_que_hacer'] = $origen === 0 && $plan['zl_stock_filas'] === 0 && $historial === [];
        $plan['huella'] = hash('sha256', (string) json_encode([
            $zlId, $plan['origen'], $plan['puros'], $plan['puros_promover'], $plan['icg'], $plan['preexistentes'], $plan['restaurables_validos'], $plan['zl_stock_filas'],
            $huellas, $idsConDocumentos, $conservarHistorial, array_keys($hijas),
        ]));

        return $plan;
    }

    /**
     * Detalle, uno por uno, de los productos restaurables que usan documentos: valores actuales, valores que quedarían tras restaurar y contraste con
     * `legacy.productos` (nombre y referencia de proveedor) para revisión humana.
     *
     * @param Tablas $t
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function detalleDocumentales(array $t, string $cte, array $ids): array
    {
        $legacyJoin = $t['legacy'] !== null ? "LEFT JOIN {$t['legacy']} lp ON lp.catalog_product_id = c.id" : '';
        $legacyCols = $t['legacy'] !== null ? 'lp.descripcion AS legacy_desc, lp.ref_proveedor AS legacy_ref' : 'NULL AS legacy_desc, NULL AS legacy_ref';

        $out = [];
        foreach ($ids as $id) {
            $r = DB::selectOne(
                "{$cte} SELECT c.id, c.reference, c.name, c.provider_id, c.factory_reference,
                        (o.id IS NOT NULL AND jsonb_exists(o.ov, 'provider_id')) AS valido,
                        CASE WHEN o.id IS NOT NULL AND jsonb_exists(o.ov, 'name') THEN o.ov->>'name' ELSE c.name END AS name_restaurado,
                        CASE WHEN o.id IS NOT NULL AND jsonb_exists(o.ov, 'factory_reference') THEN o.ov->>'factory_reference' ELSE c.factory_reference END AS fref_restaurada,
                        {$legacyCols}, array_to_string(ed.keys, ',') AS editadas
                 FROM {$t['cp']} c LEFT JOIN original o ON o.id = c.id LEFT JOIN ediciones ed ON ed.id = c.id {$legacyJoin}
                 WHERE c.id = ?",
                [$id],
            );
            if ($r === null) {
                continue;
            }

            $out[] = [
                'id'                => (int) $r->id,
                'reference'         => $r->reference,
                'name'              => $r->name,
                'provider_id'       => $r->provider_id,
                'factory_reference' => $r->factory_reference,
                'valido'            => (bool) $r->valido,
                'name_restaurado'   => $r->name_restaurado,
                'fref_restaurada'   => $r->fref_restaurada,
                'legacy_desc'       => $r->legacy_desc,
                'legacy_ref'        => $r->legacy_ref,
                'editadas'          => (string) ($r->editadas ?? ''),
                'difiere_de_legacy' => $r->legacy_desc !== null && mb_strtolower(trim((string) $r->name_restaurado)) !== mb_strtolower(trim((string) $r->legacy_desc)),
            ];
        }

        return $out;
    }

    /**
     * Análisis del historial de prueba contra la bodega: qué filas se anularían, qué precondiciones lo impiden, qué filas hijas caen en CASCADE y qué
     * estados quedarían desfasados. Nada se anula si hay filas que además tocan una bodega operativa, devoluciones con nota de crédito, recepciones
     * confirmadas o de órdenes recibidas/pagadas.
     *
     * @param Tablas $t
     * @return array{0: array<string, array{columnas: list<string>, filas: int, where: string, ids: list<int>}>, 1: list<string>, 2: list<array{tabla: string, filas: int}>, 3: array<string, int>, 4: list<string>, 5: list<array{tabla: string, filas: int}>}
     */
    private function analizarHistorial(array $t, int $zlId, bool $conservar): array
    {
        $historial = [];
        $bloqueos = [];
        $otras = [];
        $hijas = [];
        $desfases = [];
        $conservables = [];

        foreach ($this->historialContraBodega($t) as $tabla => $columnas) {
            $toca = self::whereTocaBodega($columnas, $zlId);
            $k = $this->contar('', "SELECT 1 FROM {$tabla} WHERE {$toca}");
            if ($k === 0) {
                continue;
            }

            $corto = substr($tabla, (int) strrpos($tabla, '.') + 1);
            if (! in_array($corto, self::HISTORIAL_TABLAS, true)) {
                // Conservable (p. ej. order_items): con la bandera se acepta tal cual (no se toca); sin ella, y siempre para una tabla desconocida, bloquea.
                if ($conservar && in_array($corto, self::HISTORIAL_CONSERVABLE, true)) {
                    $conservables[] = ['tabla' => $tabla, 'filas' => $k];
                } else {
                    $otras[] = ['tabla' => $tabla, 'filas' => $k];
                }

                continue;
            }
            if ($conservar) {
                continue;
            }

            $solo = self::whereSoloBodega($columnas, $zlId);
            $filas = $this->contar('', "SELECT 1 FROM {$tabla} WHERE {$solo}");
            $mixtas = $k - $filas;
            if ($mixtas > 0) {
                $bloqueos[] = "{$tabla}: {$mixtas} filas tocan Zona Libre Y otra bodega operativa (anularlas descuadraría el kardex de esa bodega): ids "
                    . implode(', ', $this->listarIds('', "SELECT id FROM {$tabla} WHERE {$toca} AND NOT ({$solo})")) . '.';
            }

            $historial[$tabla] = ['columnas' => $columnas, 'filas' => $filas, 'where' => $solo, 'ids' => $this->listarIds('', "SELECT id FROM {$tabla} WHERE {$solo}")];

            foreach ($this->fks($tabla, true) as $hija) {
                $nombre = "{$hija['schema']}.{$hija['tabla']}";
                $hijas[$nombre] = ($hijas[$nombre] ?? 0) + $this->contar('', "SELECT 1 FROM {$nombre} WHERE {$hija['columna']} IN (SELECT id FROM {$tabla} WHERE {$solo})");
            }

            [$b, $d] = $this->precondicionesPorTabla($t, $corto, $tabla, $solo);
            array_push($bloqueos, ...$b);
            array_push($desfases, ...$d);
        }

        return [$historial, $bloqueos, $otras, $hijas, $desfases, $conservables];
    }

    /**
     * Precondiciones y efectos agregados específicos de cada tabla de historial.
     *
     * @param Tablas $t
     * @return array{0: list<string>, 1: list<string>} [bloqueos, desfases informativos]
     */
    private function precondicionesPorTabla(array $t, string $corto, string $tabla, string $solo): array
    {
        $bloqueos = [];
        $desfases = [];

        if ($corto === 'customer_returns') {
            $ids = $this->listarIds('', "SELECT id FROM {$tabla} WHERE ({$solo}) AND credit_note_id IS NOT NULL");
            if ($ids !== []) {
                $bloqueos[] = "{$tabla}: devoluciones con Nota de Crédito emitida (credit_note_id): ids " . implode(', ', $ids) . ' — anularlas dejaría la nota huérfana de su origen.';
            }
        }

        if ($corto === 'purchase_order_receipt_lines') {
            $esquema = substr($tabla, 0, (int) strrpos($tabla, '.'));
            $filas = DB::select(
                "SELECT l.id AS linea, l.confirmed_at IS NOT NULL AS confirmada, po.id AS orden, po.status,
                        EXISTS (SELECT 1 FROM {$esquema}.purchase_order_payments pg WHERE pg.purchase_order_id = po.id) AS con_pagos
                 FROM {$tabla} l
                 JOIN {$esquema}.purchase_order_receipts r ON r.id = l.purchase_order_receipt_id
                 JOIN {$esquema}.purchase_orders po ON po.id = r.purchase_order_id
                 WHERE l.id IN (SELECT id FROM {$tabla} WHERE {$solo}) ORDER BY po.id, l.id"
            );
            foreach ($filas as $f) {
                $pagos = (bool) $f->con_pagos ? 'con pagos registrados' : 'sin pagos';
                $desfases[] = "Orden de compra {$f->orden} (estado {$f->status}, {$pagos}): la línea de recepción {$f->linea} se anularía y el estado de la orden quedaría desfasado.";
                if ((bool) $f->confirmada) {
                    $bloqueos[] = "{$tabla}: la línea {$f->linea} (orden {$f->orden}) ya fue CONFIRMADA por Bodega: no se anula.";
                }
                // El estado de pago no es una columna: se deriva de los pagos registrados (`PurchaseOrder::paymentStatus()`); cualquier pago = ya no está pendiente.
                if ($f->status === 'recibido' || (bool) $f->con_pagos) {
                    $bloqueos[] = "{$tabla}: la línea {$f->linea} pertenece a la orden {$f->orden} ya recibida o con pagos (estado {$f->status}, {$pagos}): anularla la dejaría incoherente.";
                }
            }
        }

        if (Schema::hasColumn($tabla, 'catalog_product_id')) {
            foreach (DB::select(
                "SELECT p.id, p.reference, p.stock_quantity FROM {$t['cp']} p
                 WHERE p.id IN (SELECT DISTINCT catalog_product_id FROM {$tabla} WHERE {$solo}) ORDER BY p.id LIMIT 50"
            ) as $p) {
                $desfases[] = "Producto {$p->id} ({$p->reference}): catalog_products.stock_quantity = " . ($p->stock_quantity ?? 'NULL') . " no se recalcula al anular {$tabla}.";
            }
        }

        return [$bloqueos, $desfases];
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private function bloqueos(array $plan): array
    {
        $m = [];
        if ($plan['bak_existentes'] !== []) {
            $m[] = 'Ya existen tablas de respaldo (' . implode(', ', $plan['bak_existentes']) . '): una corrida previa terminó a medias o no se limpió. Revísalas antes de continuar.';
        }
        if ($plan['origen'] > 0 && $plan['lafayette_productos'] < (int) floor($plan['origen'] * (float) config('lafayette.aislar_min_poblado_ratio', 0.9))) {
            $m[] = "lafayette_productos tiene {$plan['lafayette_productos']} filas para {$plan['origen']} productos de origen Lafayette: repoblarla primero con lafayette:sync-productos (si no, la limpieza perdería el dato).";
        }
        foreach ($plan['otras_referencias_bodega'] as $o) {
            $m[] = "La tabla {$o['tabla']} tiene {$o['filas']} filas contra la bodega Zona Libre y NO es historial anulable (configuración o trazabilidad distinta)" . ($this->option('conservar-historial-zona-libre') ? ' ni conservable (tabla desconocida)' : ' (si es conservable, usa --conservar-historial-zona-libre)') . ': decidir aparte antes de limpiar.';
        }
        foreach ($plan['bloqueos_historial'] as $b) {
            $m[] = $b;
        }
        if ($plan['puros_sin_evidencia'] > 0 && ! (bool) $this->option('permitir-puros-sin-evidencia')) {
            $m[] = "{$plan['puros_sin_evidencia']} productos \"puros\" no tienen NINGÚN evento de creación en audit_logs (sin evidencia de que los creó el sincronizador): revisarlos o usar --permitir-puros-sin-evidencia.";
        }
        $docsSinEvento = array_filter($plan['icg_documentales'], static fn (array $d): bool => ! $d['valido']);
        if ($docsSinEvento !== []) {
            $m[] = 'Productos referenciados por documentos SIN evento de auditoría válido (con provider_id en old_values) para restaurar: ids ' . implode(', ', array_column($docsSinEvento, 'id')) . '.';
        }
        if ($plan['restaurables_sin_valido'] > 0 && $docsSinEvento === [] && ! (bool) $this->option('permitir-icg-sin-auditoria')) {
            $m[] = "{$plan['restaurables_sin_valido']} productos restaurables no tienen evento válido para restaurar (sin documentos que los usen). Usa --permitir-icg-sin-auditoria para continuar: solo se les quitará la marca y el proveedor de Lafayette.";
        }

        return $m;
    }

    // ── Reporte ──────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $plan */
    private function reportar(array $plan): void
    {
        $this->info('lafayette:aislar-catalogo — ' . ($this->option('apply') ? 'APLICAR' : 'DRY-RUN') . ' (entorno ' . $this->entorno() . ')');
        $this->table(['Medida', 'Valor'], [
            ['Productos de origen Lafayette en catalog_products', $plan['origen']],
            ['  PUROS (los creó el sincronizador, sin enlace con AtlanticERP)', $plan['puros']],
            ['    sin referencias -> se BORRAN', $plan['puros_borrar']],
            ['    referenciados por documentos/stock -> se PROMUEVEN a productos de AtlanticERP (no se borran)', $plan['puros_promover']],
            ['    con evento de creación en audit_logs', $plan['puros_con_evidencia']],
            ['    SIN ningún evento de creación (sin evidencia)', $plan['puros_sin_evidencia']],
            ['  ICG (enlazados a legacy.productos) -> se RESTAURAN', $plan['icg']],
            ['  PREEXISTENTES no-ICG (con evento de enlace) -> se RESTAURAN, nunca se borran', $plan['preexistentes']],
            ['  restaurables con evento válido (provider_id en old_values)', $plan['restaurables_validos']],
            ['  restaurables SIN evento válido', $plan['restaurables_sin_valido']],
            ['Campos editados por usuarios después del enlace (productos listados)', count($plan['ediciones_de_usuario'])],
            ['Stock en Bodega Zona Libre (filas / unidades) -> se BORRAN', "{$plan['zl_stock_filas']} / {$plan['zl_stock_unidades']}"],
            ['Stock en las demás bodegas (filas / unidades) -> NO cambia', "{$plan['otras_stock_filas']} / {$plan['otras_stock_unidades']}"],
            ['catalog_products total (antes)', $plan['cp_total']],
            ['lafayette_productos (espejo ya cargado)', $plan['lafayette_productos']],
            ['Proveedor "Lafayette Lighting" (id)', $plan['provider_lafayette'] ?? '(no existe)'],
        ]);

        if ($plan['promover_detalle'] !== []) {
            $this->newLine();
            $this->line('<comment>Puros REFERENCIADOS que se PROMUEVEN (pierden la marca de Lafayette, conservan su dato; las referencias no cambian):</comment>');
            $this->table(['id', 'reference', 'name', 'usado por (tabla.columna: líneas)'], array_map(
                static fn (array $d): array => [$d['id'], $d['reference'], $d['name'], implode(' | ', array_map(static fn (string|int $k, int $v): string => "{$k}: {$v}", array_keys($d['usos']), array_values($d['usos'])))],
                $plan['promover_detalle'],
            ));
        }

        if ($plan['historial_conserva'] !== []) {
            $this->newLine();
            $this->line('<comment>Historial contra Zona Libre que se CONSERVA tal cual (--conservar-historial-zona-libre; la bodega se conserva, nada se anula ni se modifica):</comment>');
            $this->table(['Tabla', 'Filas'], array_map(static fn (array $o): array => [$o['tabla'], $o['filas']], $plan['historial_conserva']));
        }

        if ($plan['preexistentes'] > 0) {
            $this->warn("Hay {$plan['preexistentes']} productos PREEXISTENTES no-ICG (p. ej. creados a mano en AtlanticERP y vinculados por el loader viejo): se restauran, no se borran.");
        }

        if ($plan['ediciones_de_usuario'] !== []) {
            $this->newLine();
            $this->line('<comment>Campos editados POR USUARIOS después de que Lafayette los pisó (NO se restauran: se conserva el valor actual):</comment>');
            $this->table(['id', 'campos'], array_map(static fn (array $e): array => [$e['id'], $e['campos']], $plan['ediciones_de_usuario']));
        }

        if ($plan['historial'] !== []) {
            $this->newLine();
            $this->line('<comment>Historial de prueba contra Bodega Zona Libre que se ANULA (respaldo + DELETE; solo filas que ÚNICAMENTE tocan Zona Libre):</comment>');
            $this->table(['Tabla', 'Columnas', 'Filas', 'ids (hasta ' . self::MAX_LISTADOS . ')'], array_map(
                static fn (int|string $tabla, array $h): array => [(string) $tabla, implode(', ', $h['columnas']), $h['filas'], implode(', ', $h['ids'])],
                array_keys($plan['historial']),
                array_values($plan['historial']),
            ));
            if ($plan['hijas_cascade'] !== []) {
                $this->line('Filas hijas que caen en CASCADE (también se respaldan): ' . implode(', ', array_map(static fn (int|string $k, int $v): string => "{$k}: {$v}", array_keys($plan['hijas_cascade']), array_values($plan['hijas_cascade']))));
            }
            if ($plan['desfases'] !== []) {
                $this->newLine();
                $this->line('<comment>Estados que quedarían DESFASADOS al anular (no se recalculan: aprobar por entorno):</comment>');
                foreach ($plan['desfases'] as $d) {
                    $this->line("  - {$d}");
                }
            }
        } elseif ($plan['conservar_historial']) {
            $this->comment('Historial contra Zona Libre: se CONSERVA (--conservar-historial-zona-libre).');
        }

        if ($plan['icg_documentales'] !== []) {
            $this->newLine();
            $this->line('<comment>Productos restaurables referenciados por documentos (se verifican uno por uno; contrastados con legacy.productos):</comment>');
            $this->table(
                ['id', 'reference', 'name (actual)', 'name (restaurado)', 'legacy.descripcion', 'factory_ref (actual -> restaurada)', 'ref. proveedor legacy', 'válido', 'editadas', 'REVISAR'],
                array_map(static fn (array $d): array => [
                    $d['id'], $d['reference'], $d['name'], $d['name_restaurado'], $d['legacy_desc'] ?? '-',
                    ($d['factory_reference'] ?? 'NULL') . ' -> ' . ($d['fref_restaurada'] ?? 'NULL'), $d['legacy_ref'] ?? '-',
                    $d['valido'] ? 'sí' : 'NO', $d['editadas'] === '' ? '-' : $d['editadas'], $d['difiere_de_legacy'] || ! $d['valido'] ? 'SÍ' : '',
                ], $plan['icg_documentales']),
            );
        }
    }

    // ── Aplicación (una transacción, con bloqueo y plan recalculado) ────────────────────────────────────────

    /**
     * @param Tablas $t
     * @param array<string, mixed> $planConfirmado
     * @return array<string, mixed>
     */
    private function aplicar(array $t, array $planConfirmado, int $zlId, bool $conservar): array
    {
        return DB::transaction(function () use ($t, $planConfirmado, $zlId, $conservar): array {
            // Enteros validados en handle(): se interpolan como milisegundos. SET LOCAL dura lo que la transacción y se revierte con ella.
            DB::statement('SET LOCAL lock_timeout = \'' . ((int) $this->option('lock-timeout') * 1000) . 'ms\'');
            DB::statement('SET LOCAL statement_timeout = \'' . ((int) $this->option('statement-timeout') * 1000) . 'ms\'');
            $this->bloquearTablas($t, $planConfirmado);

            // TOCTOU: el plan que se confirmó pudo quedar viejo (usuarios, el sincronizador, ICG). Se recalcula AQUÍ, con las tablas bloqueadas.
            $plan = $this->analizar($t, $zlId, $conservar);
            if ($plan['huella'] !== $planConfirmado['huella']) {
                throw new RuntimeException('El estado de los datos cambió después de confirmar el plan (huella distinta): repite el dry-run y vuelve a confirmar.');
            }
            $bloqueos = $this->bloqueos($plan);
            if ($bloqueos !== []) {
                throw new RuntimeException('Con las tablas bloqueadas aparecieron bloqueos: ' . implode(' | ', $bloqueos));
            }

            $cp = $t['cp'];
            $cte = $this->cte($t);
            $s = $this->conjuntos($t, $plan['refs'], $zlId);
            $antes = [
                'cp'                   => (int) $plan['cp_total'],
                'stock_otras_filas'    => (int) $plan['otras_stock_filas'],
                'stock_otras_unidades' => (int) $plan['otras_stock_unidades'],
                'lafayette_productos'  => (int) $plan['lafayette_productos'],
            ];

            // 1. Respaldo (en la misma transacción: si algo falla, desaparece con el ROLLBACK), incluidas las filas hijas que caerán en CASCADE.
            DB::statement("CREATE TABLE {$cp}" . self::BAK . " AS SELECT * FROM {$cp} WHERE external_source = '" . self::EXTERNAL_SOURCE . "'");
            DB::statement("CREATE TABLE {$t['stock']}" . self::BAK . " AS SELECT * FROM {$t['stock']} WHERE warehouse_id = {$zlId}");
            foreach ($plan['historial'] as $tabla => $h) {
                DB::statement("CREATE TABLE {$tabla}" . self::BAK . " AS SELECT * FROM {$tabla} WHERE {$h['where']}");
                foreach ($this->fks($tabla, true) as $hija) {
                    $nombre = "{$hija['schema']}.{$hija['tabla']}";
                    DB::statement("CREATE TABLE {$nombre}" . self::BAK . " AS SELECT * FROM {$nombre} WHERE {$hija['columna']} IN (SELECT id FROM {$tabla} WHERE {$h['where']})");
                }
            }

            // 2. Restaurar los restaurables con evento válido desde el valor MÁS ANTIGUO por clave; los campos editados por un usuario se conservan.
            $sets = [];
            foreach (self::RESTORE_FIELDS as $campo => $cast) {
                $valor = $cast === null ? "o.ov->>'{$campo}'" : "(o.ov->>'{$campo}')::{$cast}";
                $sets[] = "{$campo} = CASE WHEN jsonb_exists(o.ov, '{$campo}') AND NOT (COALESCE(ed.keys, ARRAY[]::text[]) @> ARRAY['{$campo}']) THEN {$valor} ELSE c.{$campo} END";
            }
            $restaurados = DB::affectingStatement(
                "{$cte} UPDATE {$cp} c SET " . implode(', ', $sets) . ", external_source = NULL, external_synced_at = NULL, updated_at = now()
                 FROM original o LEFT JOIN ediciones ed ON ed.id = o.id
                 WHERE c.id = o.id AND c.id IN ({$s['validos']})"
            );

            // Restaurables SIN evento válido (solo con --permitir-icg-sin-auditoria y jamás referenciados por documentos): marca y proveedor de Lafayette fuera.
            $sinEvento = 0;
            if ($plan['restaurables_sin_valido'] > 0) {
                $sinEvento = DB::affectingStatement(
                    "{$cte} UPDATE {$cp} SET external_source = NULL, external_synced_at = NULL, updated_at = now(),
                            provider_id = CASE WHEN provider_id IS NOT DISTINCT FROM ? THEN NULL ELSE provider_id END
                     WHERE id IN ({$s['sin_valido']})",
                    [$plan['provider_lafayette']],
                );
            }

            // 2b. Promover los puros referenciados: solo pierden la marca de Lafayette (ya respaldados arriba); nombre, precio, proveedor y referencia no se tocan.
            $promovidosIds = array_map(
                static fn ($r): int => (int) $r->id,
                DB::select("{$cte} UPDATE {$cp} SET external_source = NULL, external_synced_at = NULL, updated_at = now() WHERE id IN ({$s['puros_promover']}) RETURNING id"),
            );
            sort($promovidosIds);

            // 3. Borrar el stock de Zona Libre.
            $stockBorrado = DB::affectingStatement("DELETE FROM {$t['stock']} WHERE warehouse_id = {$zlId}");

            // 4. Borrar los puros: lo que sigue marcado y sin evento de enlace ni vínculo ICG (ya sin referencias, comprobado bajo bloqueo).
            $purosBorrados = DB::affectingStatement(
                "{$cte} DELETE FROM {$cp} WHERE external_source = '" . self::EXTERNAL_SOURCE . "'
                 AND id NOT IN (SELECT id FROM enlace) AND id NOT IN (" . $this->sqlEnlazados($t) . ')'
            );

            // 5. Anular el historial de prueba contra Zona Libre (solo las filas que ÚNICAMENTE la tocan).
            $historialBorrado = [];
            foreach ($plan['historial'] as $tabla => $h) {
                $historialBorrado[$tabla] = DB::affectingStatement("DELETE FROM {$tabla} WHERE {$h['where']}");
            }

            // 6. Verificaciones: cualquier discrepancia lanza y la transacción entera hace ROLLBACK.
            $this->verificar($t, $plan, $antes, $purosBorrados, $historialBorrado, $promovidosIds);

            // 7. Evento de auditoría resumen DENTRO de la transacción: si no se puede escribir, la limpieza no ocurre.
            $resultado = [
                'restaurados'                => $restaurados,
                'restaurables_sin_evento_desmarcados' => $sinEvento,
                'puros_borrados'             => $purosBorrados,
                'puros_promovidos'           => count($promovidosIds),
                'ids_puros_promovidos'       => array_slice($promovidosIds, 0, self::MAX_LISTADOS),
                'stock_zona_libre_borrado'   => $stockBorrado,
                'historial_anulado'          => $historialBorrado,
                'hijas_cascade'              => $plan['hijas_cascade'],
                'icg_documentales_verificados' => count($plan['icg_documentales']),
                'campos_de_usuario_conservados' => count($plan['ediciones_de_usuario']),
            ];
            $this->auditar($t, $plan, $resultado);

            $resultado['huella_plan'] = $plan['huella'];
            $resultado['tablas_respaldo'] = $this->bakExistentes($t);

            return $resultado;
        });
    }

    /**
     * @param Tablas $t
     * @param array<string, mixed> $plan
     */
    private function bloquearTablas(array $t, array $plan): void
    {
        $tablas = [$t['cp'], $t['stock'], $t['lafprod']];
        if ($t['legacy'] !== null) {
            $tablas[] = $t['legacy'];
        }
        foreach ($plan['refs'] as $ref) {
            $tablas[] = "{$ref['schema']}.{$ref['tabla']}";
        }
        foreach (array_keys($plan['historial']) as $tabla) {
            $tablas[] = (string) $tabla;
        }
        foreach (array_keys($plan['hijas_cascade']) as $tabla) {
            $tablas[] = (string) $tabla;
        }

        DB::statement('LOCK TABLE ' . implode(', ', array_values(array_unique($tablas))) . ' IN SHARE ROW EXCLUSIVE MODE');
    }

    /**
     * Evento resumen: quién (operador, usuario del SO, host), dónde (entorno, tenant), qué (conteos, ids de lo que se anula y de los productos con
     * documentos, huellas SHA-256 de las listas grandes). Las listas completas de ids borrados/restaurados están en las tablas de respaldo.
     *
     * @param Tablas $t
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resultado
     */
    private function auditar(array $t, array $plan, array $resultado): void
    {
        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED,
            'entity_type' => 'catalog_products',
            'metadata'    => $resultado + [
                'operador'          => (string) ($this->option('operador') ?? ''),
                'usuario_so'        => \App\Modules\Legacy\Support\OperatorContext::describe('')['usuario_so'], // usuario efectivo del proceso; get_current_user() es el dueño del script y sale vacío si su uid no existe en el contenedor
                'host'              => (string) gethostname(),
                'entorno'           => $this->entorno(),
                'tenant'            => $t['slug'],
                'clasificacion'     => ['puros' => $plan['puros'], 'puros_borrar' => $plan['puros_borrar'], 'puros_promover' => $plan['puros_promover'], 'icg' => $plan['icg'], 'preexistentes' => $plan['preexistentes']],
                'huellas_md5'       => $plan['huellas'],
                'huella_plan'       => $plan['huella'],
                'ids_documentales'  => array_column($plan['icg_documentales'], 'id'),
                'ids_historial'     => array_map(static fn (array $h): array => $h['ids'], $plan['historial']),
                'desfases'          => $plan['desfases'],
                'historial_conservado' => $plan['historial_conserva'],
            ],
        ]);
    }

    /**
     * @param Tablas $t
     * @param array<string, mixed> $plan
     * @param array<string, int> $antes
     * @param array<string, int> $historialBorrado
     * @param list<int> $promovidosIds
     */
    private function verificar(array $t, array $plan, array $antes, int $purosBorrados, array $historialBorrado, array $promovidosIds): void
    {
        $cp = $t['cp'];
        $cte = $this->cte($t);
        $bak = $cp . self::BAK;
        $fallos = [];

        // Restaurables que siguen existiendo (los puros se borraron): salen del respaldo, no de la marca (ya quitada). `$validos` = los que tenían
        // evento válido y por tanto se restauraron campo a campo; los demás (solo con --permitir-icg-sin-auditoria) conservan sus valores.
        // Los puros PROMOVIDOS no son "restaurados": conservan su dato (incluido el proveedor Lafayette Lighting) por decisión de Luis.
        $promovidos = $promovidosIds === [] ? '-1' : implode(',', array_map('intval', $promovidosIds));
        $restaurados = "SELECT id FROM {$bak} WHERE id IN (SELECT id FROM {$cp}) AND id NOT IN ({$promovidos})";
        $validos = "{$restaurados} AND id IN (SELECT id FROM original WHERE jsonb_exists(ov, 'provider_id'))";

        $restante = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$cp} WHERE external_source = ?", [self::EXTERNAL_SOURCE])->c;
        if ($restante !== 0) {
            $fallos[] = "quedan {$restante} productos con external_source = lafayette_lighting";
        }

        $cpDespues = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$cp}")->c;
        if ($cpDespues !== $antes['cp'] - (int) $plan['puros_borrar'] || $purosBorrados !== (int) $plan['puros_borrar']) {
            $fallos[] = "catalog_products: esperado {$antes['cp']} - {$plan['puros_borrar']} = " . ($antes['cp'] - (int) $plan['puros_borrar']) . ", quedó {$cpDespues} (borrados {$purosBorrados})";
        }

        // Puros promovidos: siguen existiendo, ya sin la marca de Lafayette, conservan sus valores y sus referencias no cambiaron.
        if (count($promovidosIds) !== (int) $plan['puros_promover']) {
            $fallos[] = 'se promovieron ' . count($promovidosIds) . " puros y se esperaban {$plan['puros_promover']}";
        }
        if ($promovidosIds !== []) {
            $lista = implode(',', array_map('intval', $promovidosIds));
            $existen = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$cp} WHERE id IN ({$lista}) AND external_source IS NULL AND external_synced_at IS NULL")->c;
            if ($existen !== count($promovidosIds)) {
                $fallos[] = "solo {$existen} de " . count($promovidosIds) . ' puros promovidos existen sin la marca de Lafayette';
            }
            $cambiados = (int) DB::selectOne(
                "SELECT COUNT(*) AS c FROM {$cp} c JOIN {$bak} b ON b.id = c.id WHERE c.id IN ({$lista})
                 AND (c.name IS DISTINCT FROM b.name OR c.reference IS DISTINCT FROM b.reference OR c.provider_id IS DISTINCT FROM b.provider_id
                      OR c.price_full IS DISTINCT FROM b.price_full OR c.factory_reference IS DISTINCT FROM b.factory_reference)"
            )->c;
            if ($cambiados !== 0) {
                $fallos[] = "{$cambiados} puros promovidos cambiaron de valor (nombre, referencia, proveedor o precio)";
            }
            $bakPromovidos = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$bak} WHERE id IN ({$lista})")->c;
            if ($bakPromovidos !== count($promovidosIds)) {
                $fallos[] = "el respaldo solo contiene {$bakPromovidos} de los " . count($promovidosIds) . ' puros promovidos';
            }
        }
        foreach ($plan['promover_refs'] as $clave => $filasAntes) {
            $esStock = str_ends_with($clave, ' (otras bodegas)');
            [$tabla, $col] = $esStock ? [substr($clave, 0, -strlen(' (otras bodegas)')), 'catalog_product_id'] : [substr($clave, 0, (int) strrpos($clave, '.')), substr($clave, (int) strrpos($clave, '.') + 1)];
            $extra = $esStock ? " AND warehouse_id <> {$plan['zl_id']}" : '';
            $lista = $promovidosIds === [] ? 'NULL' : implode(',', array_map('intval', $promovidosIds));
            $despues = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$tabla} WHERE {$col} IN ({$lista}){$extra}")->c;
            $anuladas = array_key_exists($tabla, $historialBorrado) ? 'anulada' : '';
            if ($despues !== $filasAntes && $anuladas === '') {
                $fallos[] = "las referencias de los puros promovidos en {$clave} cambiaron de {$filasAntes} a {$despues}";
            }
        }

        $zlRestante = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$t['stock']} WHERE warehouse_id = ?", [$plan['zl_id']])->c;
        if ($zlRestante !== 0) {
            $fallos[] = "quedan {$zlRestante} filas de stock en Zona Libre";
        }

        $otras = DB::selectOne("SELECT COUNT(*) AS filas, COALESCE(SUM(quantity), 0) AS unidades FROM {$t['stock']} WHERE warehouse_id <> ?", [$plan['zl_id']]);
        if ((int) $otras->filas !== $antes['stock_otras_filas'] || (int) $otras->unidades !== $antes['stock_otras_unidades']) {
            $fallos[] = "el stock de las bodegas operativas cambió: {$antes['stock_otras_filas']}/{$antes['stock_otras_unidades']} -> {$otras->filas}/{$otras->unidades}";
        }

        // Comprobaciones INDEPENDIENTES del old_values con el que se restauró (no pueden pasar por reutilizar el mismo dato).
        if ($plan['provider_lafayette'] !== null) {
            $conProveedor = (int) DB::selectOne("{$cte} SELECT COUNT(*) AS c FROM {$cp} WHERE provider_id = ? AND id IN ({$restaurados})", [$plan['provider_lafayette']])->c;
            if ($conProveedor > 0) {
                $fallos[] = "{$conProveedor} productos restaurados siguen con el proveedor Lafayette Lighting";
            }
        }
        $conIdLafayette = (int) DB::selectOne(
            "{$cte} SELECT COUNT(*) AS c FROM {$cp} WHERE id IN ({$validos}) AND factory_reference IN (SELECT lafayette_id FROM {$t['lafprod']})"
        )->c;
        if ($conIdLafayette > 0) {
            $fallos[] = "{$conIdLafayette} productos restaurados conservan como factory_reference un id de Lafayette";
        }
        $sets = [];
        foreach (self::RESTORE_FIELDS as $campo => $cast) {
            $valor = $cast === null ? "o.ov->>'{$campo}'" : "(o.ov->>'{$campo}')::{$cast}";
            $sets[] = "(jsonb_exists(o.ov, '{$campo}') AND NOT (COALESCE(ed.keys, ARRAY[]::text[]) @> ARRAY['{$campo}']) AND c.{$campo} IS DISTINCT FROM {$valor})";
        }
        $distintos = (int) DB::selectOne(
            "{$cte} SELECT COUNT(*) AS c FROM {$cp} c JOIN original o ON o.id = c.id LEFT JOIN ediciones ed ON ed.id = c.id
             WHERE c.id IN ({$validos}) AND (" . implode(' OR ', $sets) . ')'
        )->c;
        if ($distintos !== 0) {
            $fallos[] = "{$distintos} productos restaurados no coinciden con el valor original más antiguo";
        }
        $perdidaFref = (int) DB::selectOne(
            "{$cte} SELECT COUNT(*) AS c FROM {$cp} c JOIN original o ON o.id = c.id LEFT JOIN ediciones ed ON ed.id = c.id
             WHERE c.id IN ({$validos}) AND jsonb_exists(o.ov, 'factory_reference') AND o.ov->>'factory_reference' IS NOT NULL
               AND NOT (COALESCE(ed.keys, ARRAY[]::text[]) @> ARRAY['factory_reference']) AND c.factory_reference IS NULL"
        )->c;
        if ($perdidaFref !== 0) {
            $fallos[] = "{$perdidaFref} productos que tenían factory_reference quedaron sin ella";
        }

        // Los productos usados por documentos: uno por uno.
        foreach ($plan['icg_documentales'] as $d) {
            $r = DB::selectOne("SELECT id, external_source, provider_id FROM {$cp} WHERE id = ?", [$d['id']]);
            if ($r === null) {
                $fallos[] = "el producto {$d['id']} ({$d['reference']}), usado por documentos, ya no existe";
            } elseif ($r->external_source !== null) {
                $fallos[] = "el producto {$d['id']} sigue marcado como de Lafayette";
            } elseif ($plan['provider_lafayette'] !== null && (int) $r->provider_id === (int) $plan['provider_lafayette']) {
                $fallos[] = "el producto {$d['id']} (usado por documentos) sigue con el proveedor Lafayette Lighting";
            }
        }

        // Ningún documento cambió: las tablas que referencian productos conservan sus filas, salvo las del historial anulado y sus hijas en CASCADE.
        $anuladas = array_map('strval', array_keys($historialBorrado));
        foreach ($plan['conteo_referencias'] as $clave => $filasAntes) {
            [$esquema, $tabla] = explode('.', $clave);
            $tablaCompleta = "{$esquema}.{$tabla}";
            if (in_array($tablaCompleta, $anuladas, true)) {
                continue;
            }
            $esperadas = $filasAntes - (int) ($plan['hijas_cascade'][$tablaCompleta] ?? 0);
            $despues = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$tablaCompleta}")->c;
            if ($despues !== $esperadas) {
                $fallos[] = "{$tablaCompleta} cambió de {$filasAntes} a {$despues} filas (esperadas {$esperadas})";
            }
        }

        if ((int) DB::selectOne("SELECT COUNT(*) AS c FROM {$t['lafprod']}")->c !== $antes['lafayette_productos']) {
            $fallos[] = 'lafayette_productos cambió durante la limpieza';
        }

        // Respaldos completos.
        $bakCp = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$bak}")->c;
        if ($bakCp !== (int) $plan['origen']) {
            $fallos[] = "el respaldo de catalog_products tiene {$bakCp} filas y se esperaban {$plan['origen']}";
        }
        $bakStock = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$t['stock']}" . self::BAK)->c;
        if ($bakStock !== (int) $plan['zl_stock_filas']) {
            $fallos[] = "el respaldo del stock de Zona Libre tiene {$bakStock} filas y se esperaban {$plan['zl_stock_filas']}";
        }
        foreach ($plan['historial'] as $tabla => $h) {
            $bakFilas = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$tabla}" . self::BAK)->c;
            if ($bakFilas !== $h['filas'] || ($historialBorrado[$tabla] ?? -1) !== $h['filas']) {
                $fallos[] = "historial {$tabla}: respaldo {$bakFilas}, borradas " . ($historialBorrado[$tabla] ?? 0) . ", esperadas {$h['filas']}";
            }
        }
        foreach ($plan['hijas_cascade'] as $hija => $n) {
            $bakHija = (int) DB::selectOne("SELECT COUNT(*) AS c FROM {$hija}" . self::BAK)->c;
            if ($bakHija !== $n) {
                $fallos[] = "respaldo de {$hija}: {$bakHija} filas y se esperaban {$n}";
            }
        }

        if ($fallos !== []) {
            throw new RuntimeException('verificación fallida: ' . implode('; ', $fallos));
        }
    }
}
