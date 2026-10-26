<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * SCRUM-839 — reemplaza el umbral de "10+ días sin corrida exitosa" del diseño original
 * (Illumination/migracion-icg-diseno.md §11, vacío #5) por un chequeo atado a cada slot esperado
 * del cron de SCRUM-838: agendado ~1h después de cada slot (routes/console.php), busca en
 * `legacy.sync_runs` una fila `status=success` cuyo `started_at` caiga dentro de la ventana del
 * slot recién vencido, por cada una de las 9 entidades.
 *
 * - Sin ninguna corrida histórica para una entidad → NUNCA alerta (estado inicial esperable antes
 *   de la primera vez, no una falla).
 * - Ninguna fila dentro de la ventana → la corrida se SALTÓ (el scheduler no la disparó, o quedó
 *   colgada) → alerta.
 * - Fila dentro de la ventana con status=failed o errores>0 → alerta (sin esperar el siguiente
 *   chequeo).
 * - Fila dentro de la ventana con status=held_for_review (circuit breaker de SCRUM-838, cambio de
 *   volumen anormal) → NO se cuenta como "saltada" ni dispara una segunda alerta acá — el circuit
 *   breaker del comando incremental correspondiente ya alertó al momento de detectarlo (mismo
 *   `LegacySyncAlertService`), evita duplicar el mismo evento.
 * - Fila dentro de la ventana con status=success → no alerta.
 *
 * Sin cooldown artificial — el propio chequeo, corrido una vez por slot ya vencido, evita alertas
 * duplicadas dentro del mismo período (criterio de aceptación del ticket).
 *
 * Ventana resuelta según el ambiente actual (coincide con la cadencia real de SCRUM-838 en
 * routes/console.php, cadencia de prod ajustada de 2x/día a 1x/día 2026-09-15 — las dos corridas
 * originales caían ambas fuera de horario laboral, así que la segunda nunca capturaba un cambio
 * real): prod corre este comando a las 03:00, ventana = [21:00 del día anterior, 03:00); test/dev
 * corre lunes 05:00, ventana = [domingo 01:00, lunes 05:00). `--window-start`/`--window-end`
 * permiten fijar la ventana en tests, sin depender de `now()`.
 */
final class LegacyCheckSyncHealthCommand extends Command
{
    private const ENTITIES = [
        'productos', 'clientes', 'proveedores', 'facturas', 'pagos',
        'cotizaciones', 'pedidos', 'albaranes', 'albaranes_compra',
        // SCRUM-1114 — stock de AL4 como delta (décimo paso de legacy:sync-incremental-all).
        'stock',
    ];


    /** @var string */
    protected $signature = 'legacy:check-sync-health
        {--tenant=atlantic : Slug del tenant a chequear}
        {--window-start= : Override ISO8601 del inicio de ventana (testing)}
        {--window-end= : Override ISO8601 del fin de ventana (testing)}';

    /** @var string */
    protected $description = 'SCRUM-839 — alerta si una corrida incremental esperada según el cron de SCRUM-838 se saltó o falló';

    public function __construct(private readonly LegacySyncAlertService $alerts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSlug = (string) $this->option('tenant');
        $tenant     = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        [$windowStart, $windowEnd] = $this->resolveWindow();

        $tenant->makeCurrent();
        try {
            $stockActivo = StockSyncSettings::current()->activo;
            foreach (self::ENTITIES as $entidad) {
                // Con el interruptor apagado el sync de stock no corre a propósito: no es una corrida "saltada"
                // (en producción lo cubre `checkStockDisabled()` tras el período de gracia).
                if ($entidad === 'stock' && ! $stockActivo) {
                    continue;
                }
                $this->checkEntity($entidad, $windowStart, $windowEnd);
            }
            $this->checkStockDisabled();
        } finally {
            Tenant::forgetCurrent();
        }

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveWindow(): array
    {
        $overrideStart = $this->option('window-start');
        $overrideEnd   = $this->option('window-end');
        if (is_string($overrideStart) && $overrideStart !== '' && is_string($overrideEnd) && $overrideEnd !== '') {
            return [Carbon::parse($overrideStart), Carbon::parse($overrideEnd)];
        }

        $now = now();

        if (app()->environment('production')) {
            // Este comando corre a las 03:00, 6h después del único slot diario real (21:00 del día
            // ANTERIOR, ver routes/console.php) — por eso resta un día en vez de sumar horas dentro
            // del mismo `startOfDay()`. Margen de 6h (2026-09-15, mismo margen ya validado con 4h/
            // 1h en iteraciones previas): medido en vivo que la cadena completa de las 9 entidades
            // en secuencia estricta (legacy:sync-incremental-all) tarda ~1h20min (dominado por
            // Cotizaciones, ~49min) — 6h de margen cubre eso con espacio de sobra sin arriesgar
            // falsos "se saltó" mientras la cadena todavía corre.
            $start = $now->copy()->subDay()->startOfDay()->addHours(21);
            $end   = $start->copy()->addHours(6);

            return [$start, $end];
        }

        // test/dev: corre lunes 05:00, ~24h después de los slots semanales reales del domingo
        // (test 01:00, dev 05:00 desde 2026-09-28) — la ventana [domingo 01:00, lunes 05:00) cubre ambos.
        $end   = $now->copy();
        $start = $now->copy()->subDay()->startOfDay()->addHour();

        return [$start, $end];
    }

    /**
     * SCRUM-1114 — el interruptor `activo` apaga las corridas programadas de stock sin que `checkEntity`
     * lo note (una corrida que no se ejecuta por estar apagada no es "saltada"). En producción, pasadas
     * 48 h desde la primera corrida de stock, un sync apagado es una divergencia que crece: se avisa.
     */
    private function checkStockDisabled(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $settings = StockSyncSettings::current();
        if ($settings->activo || $settings->baseline_inicializada_at === null) {
            return;
        }

        $primera = SyncRun::query()->where('entidad', 'stock')->min('started_at');
        if ($primera === null || Carbon::parse((string) $primera)->gt(now()->subHours($settings->horas_aviso_apagado))) {
            return;
        }

        $this->alerts->alertDisabled('stock', sprintf(
            'La sincronización de stock de ICG está desactivada en producción (activo = false) y ya corrió antes (desde %s). El stock de AtlanticERP se aleja del de ICG cada día; reactívela o confirme que el cutover ya ocurrió.',
            Carbon::parse((string) $primera)->toDateTimeString(),
        ));
    }

    private function checkEntity(string $entidad, Carbon $windowStart, Carbon $windowEnd): void
    {
        $hasEverRun = SyncRun::query()->where('entidad', $entidad)->exists();
        if (! $hasEverRun) {
            return;
        }

        // Pre-QA 2026-09-14 — whereBetween() genera BETWEEN, inclusive en AMBOS extremos en SQL;
        // el docblock de la clase documenta la ventana como semiabierta [start, end) — usar
        // >=/< explícito para que el código haga lo que dice el comentario (irrelevante en la
        // práctica dado que el próximo slot real está a 12h/1 semana de distancia, nunca al
        // milisegundo exacto de windowEnd, pero corregido para no dejar el comportamiento
        // documentado divergiendo del real).
        /** @var SyncRun|null $runInWindow */
        $runInWindow = SyncRun::query()
            ->where('entidad', $entidad)
            ->where('started_at', '>=', $windowStart)
            ->where('started_at', '<', $windowEnd)
            ->orderByDesc('started_at')
            ->first();

        if ($runInWindow === null) {
            $ultimaExitosa = $this->lastSuccessfulRunAt($entidad);
            $this->warn("legacy:check-sync-health — {$entidad}: corrida esperada NO ENCONTRADA en la ventana [{$windowStart}, {$windowEnd}).");

            $this->alerts->alertSkippedOrFailed(
                entidad: $entidad,
                motivo: 'corrida esperada se saltó — sin fila en legacy.sync_runs dentro de la ventana del slot',
                ultimaCorridaExitosa: $ultimaExitosa,
                comandoManual: "infra/legacy-sync.sh <entorno> --{$entidad}",
            );

            return;
        }

        if ($runInWindow->status === SyncRun::STATUS_FAILED || $runInWindow->errores > 0) {
            $this->warn("legacy:check-sync-health — {$entidad}: corrida encontrada pero status={$runInWindow->status}, errores={$runInWindow->errores}.");

            $this->alerts->alertSkippedOrFailed(
                entidad: $entidad,
                motivo: "corrida terminó status={$runInWindow->status} con {$runInWindow->errores} error(es)",
                ultimaCorridaExitosa: $this->lastSuccessfulRunAt($entidad),
                comandoManual: "infra/legacy-sync.sh <entorno> --{$entidad}",
            );

            return;
        }

        // success o held_for_review — ninguno de los dos alerta acá (held_for_review ya alertó
        // desde el circuit breaker del comando incremental correspondiente).
        $this->info("legacy:check-sync-health — {$entidad}: OK (status={$runInWindow->status}).");
    }

    private function lastSuccessfulRunAt(string $entidad): ?string
    {
        $ultima = SyncRun::query()
            ->where('entidad', $entidad)
            ->where('status', SyncRun::STATUS_SUCCESS)
            ->orderByDesc('started_at')
            ->first();

        return $ultima?->started_at?->toIso8601String();
    }
}
