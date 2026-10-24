<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Support\OperatorContext;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1114 — inicializa `legacy.productos.stock_al4_aplicado` (la línea base de la sincronización de
 * stock por delta) UNA vez por entorno, antes de la primera corrida de `legacy:sync-incremental-stock`.
 * Dry-run por defecto; `--apply --operador="Nombre"` escribe.
 *
 * Por qué no basta copiar `stock_al4`: el incremental de productos lo refresca cuando cambia el hash
 * del catálogo, así que puede haberse movido después de la carga inicial. La base debe ser "lo que
 * AtlanticERP ya refleja de ICG", por producto elegible (ligado a ICG, activo, no descatalogado) y aún sin base:
 *   - sin fila en AL4 (`stock_al4` nulo)            → 0 (se sabe que no había stock);
 *   - staging NO refrescado desde la carga inicial   → `round(stock_al4)`;
 *   - staging refrescado después de la carga         → se reconstruye: `stock_quantity` menos los
 *     movimientos de kardex posteriores a la carga (lo que AtlanticERP movió por su cuenta).
 * La "carga" es el evento de auditoría de `legacy:backfill-stock-bodega-central`; sin ese evento (dev/
 * test) no se restan movimientos.
 *
 * Verificación de consistencia: la base debe igualar `stock_quantity - movimientos posteriores`. Si algún
 * producto no cuadra, `--apply` se niega (listando los primeros) salvo `--force`: sumar un delta sobre una
 * base que AtlanticERP no refleja duplicaría o perdería stock.
 *
 * Los inactivos/descatalogados quedan con base nula ("nunca cargado"). Igual un producto elegible hoy que ICG
 * tenía con stock pero que AtlanticERP nunca recibió (`stock_quantity` nulo y `stock_al4` distinto de 0: estuvo inactivo
 * o descatalogado al momento de la carga): su base queda nula para que la primera corrida lo siembre con ese stock
 * en vez de darlo por reflejado (revisión del Senior Reviewer, S3). Al terminar marca
 * `stock_sync_settings.baseline_inicializada_at`, sin lo cual el comando de sync se niega a correr.
 */
final class LegacyInitStockBaselineCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:init-stock-baseline
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe la línea base (por defecto solo informa)}
        {--operador= : Nombre de quien lo corre (obligatorio con --apply, queda en auditoría)}
        {--force : Aplica aunque algún producto no cuadre con stock_quantity}';

    /** @var string */
    protected $description = 'SCRUM-1114 — inicializa la línea base del stock de ICG (stock_al4_aplicado) para la sincronización por delta';

    public function handle(): int
    {
        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $apply    = (bool) $this->option('apply');
        $operador = trim((string) $this->option('operador'));
        if ($apply && $operador === '') {
            $this->error('--apply requiere --operador="Nombre" (queda en auditoría).');

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply, $operador, (bool) $this->option('force'));
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply, string $operador, bool $force): int
    {
        // Mismo candado que el sync y la reversa: nada de stock corre en paralelo (revisión de seguridad S-09).
        $lock = Cache::lock("legacy-sync-stock:{$slug}", 7200);
        if (! $lock->get()) {
            $this->error('Ya hay una corrida o reversa de stock en curso para este tenant.');

            return self::FAILURE;
        }

        try {
            return $this->correr($slug, $apply, $operador, $force);
        } finally {
            $lock->release();
        }
    }

    private function correr(string $slug, bool $apply, string $operador, bool $force): int
    {
        $p = (new Producto())->getTable();
        $c = (new CatalogProduct())->getTable();
        $m = (new InventoryMovement())->getTable();

        $carga = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL)->orderByDesc('created_at')->value('created_at');
        $cargaAt = $carga === null ? null : Carbon::parse((string) $carga);
        $this->line('Carga inicial de stock (auditoría): ' . ($cargaAt === null ? 'no encontrada — no se restan movimientos de kardex' : $cargaAt->toIso8601String()));

        $movs = $carga === null
            ? '0'
            : "COALESCE((SELECT SUM(m.cantidad) FROM {$m} m WHERE m.catalog_product_id = c.id AND m.created_at > ?), 0)";

        $rows = DB::table("{$p} as p")
            ->join("{$c} as c", 'c.id', '=', 'p.catalog_product_id')
            ->whereNotNull('p.catalog_product_id')
            ->where('c.is_active', true)
            ->where('p.descatalogado', false)
            ->whereNull('p.stock_al4_aplicado')
            ->selectRaw("p.id as producto_id, p.icg_codarticulo, p.stock_al4, p.updated_at as staging_at, c.stock_quantity, {$movs} as movs", $carga === null ? [] : [$carga])
            ->orderBy('p.id')
            ->get();

        $fuentes       = ['sin_fila_al4' => 0, 'staging' => 0, 'reconstruida' => 0];
        $sinCargar     = 0;
        $bases         = [];
        $inconsistente = [];

        foreach ($rows as $row) {
            if ($row->stock_al4 !== null && (int) round((float) $row->stock_al4) !== 0 && $row->stock_quantity === null) {
                $sinCargar++; // ICG tenía stock y AtlanticERP nunca lo recibió: base nula, la primera corrida lo siembra

                continue;
            }
            $refrescada = $cargaAt !== null && $row->staging_at !== null && Carbon::parse((string) $row->staging_at)->gt($cargaAt);
            if ($row->stock_al4 === null) {
                $base = 0;
                $fuentes['sin_fila_al4']++;
            } elseif ($refrescada) {
                $base = (int) ($row->stock_quantity ?? 0) - (int) $row->movs;
                $fuentes['reconstruida']++;
            } else {
                $base = (int) round((float) $row->stock_al4);
                $fuentes['staging']++;
            }

            $esperado = (int) ($row->stock_quantity ?? 0) - (int) $row->movs;
            if ($base !== $esperado) {
                $inconsistente[] = "icg {$row->icg_codarticulo}: base {$base} vs stock_quantity-movimientos {$esperado}";
            }
            $bases[(int) $row->producto_id] = $base;
        }

        $this->table(['Concepto', 'Productos'], [
            ['Elegibles sin base (a inicializar)', count($bases)],
            ['  base 0 (sin fila en AL4)', $fuentes['sin_fila_al4']],
            ['  base = round(stock_al4)', $fuentes['staging']],
            ['  base reconstruida (staging refrescado tras la carga)', $fuentes['reconstruida']],
            ['Sin cargar en AtlanticERP (base nula, la primera corrida los siembra)', $sinCargar],
            ['No cuadran con stock_quantity', count($inconsistente)],
        ]);
        foreach (array_slice($inconsistente, 0, 20) as $linea) {
            $this->line("  - {$linea}");
        }

        if (! $apply) {
            $this->comment('Dry-run: no se escribió nada. Use --apply --operador="Nombre".');

            return self::SUCCESS;
        }

        if ($inconsistente !== [] && ! $force) {
            $this->error(count($inconsistente) . ' producto(s) no cuadran con stock_quantity: no se aplica (revise la lista o use --force sabiendo que el primer delta los desajustará).');

            return self::FAILURE;
        }

        foreach (array_chunk($bases, 500, true) as $lote) {
            DB::transaction(function () use ($lote, $p): void {
                foreach ($lote as $id => $base) {
                    DB::table($p)->where('id', $id)->whereNull('stock_al4_aplicado')->update(['stock_al4_aplicado' => $base]);
                }
            });
        }

        $settings = StockSyncSettings::current();
        $settings->update(['baseline_inicializada_at' => now()]);

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_INIT_STOCK_BASELINE,
            'entity_type' => 'legacy_productos',
            'metadata'    => OperatorContext::describe($operador) + [
                'entorno'       => (string) config('app.env'),
                'tenant'        => $slug,
                'inicializados' => count($bases),
                'sin_cargar'    => $sinCargar,
                'fuentes'       => $fuentes,
                'inconsistentes' => count($inconsistente),
                'forzada'       => $force,
            ],
        ]);

        $ctx = OperatorContext::describe($operador);
        app(LegacySyncAlertService::class)->alertEvento('stock', 'Línea base de stock inicializada', [
            count($bases) . " productos con base; {$sinCargar} sin cargar" . ($force ? ' (forzada con --force: ' . count($inconsistente) . ' no cuadraban)' : ''),
            OperatorContext::linea($ctx),
        ]);

        $this->info(count($bases) . ' productos con línea base inicializada. baseline_inicializada_at marcada.');

        return self::SUCCESS;
    }
}
