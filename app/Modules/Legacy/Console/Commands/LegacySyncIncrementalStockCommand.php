<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\StockDeltaService;
use App\Modules\Legacy\Support\OperatorContext;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * SCRUM-1114 (épica SCRUM-749) — décimo paso de `legacy:sync-incremental-all`, justo después de
 * `productos`: sincroniza el stock de ICG (AL4 = Bodega Central) como DELTA sobre lo que AtlanticERP ya
 * tiene (ver `StockDeltaService` para la fórmula y las reglas). Paso propio y no parte del de
 * productos porque su circuit breaker (cambio de catálogo > 20%) mide otra cosa: si se retiene el
 * catálogo, el stock quedaría frenado sin motivo.
 *
 * - Sin `--dry-run` aplica (igual que las demás entidades incrementales); con `--dry-run` calcula e
 *   informa sin escribir nada ni registrar corrida.
 * - Se niega a correr si `baseline_inicializada_at` es null (`legacy:init-stock-baseline`): con la
 *   base sin inicializar el delta sería el stock completo de ICG otra vez.
 * - Las corridas programadas (sin `--operador`) solo corren si `stock_sync_settings.activo` es true.
 *   Una corrida manual supervisada (`--operador="Nombre"`) corre igual y queda con `origen = manual`.
 * - CIRCUIT BREAKER con umbrales de `legacy.stock_sync_settings` (nada hardcodeado): si el plan los
 *   supera la corrida queda `held_for_review`, no aplica nada y avisa por correo. `--force` aplica
 *   igual tras revisar el motivo.
 * - Una respuesta vacía de ICG se trata como fallo, nunca como "todo el stock pasó a 0".
 *
 * La corrida se registra ANTES de aplicar (los movimientos de kardex referencian su id) con status
 * `failed`; se actualiza al final. Si el proceso muere a la mitad queda `failed`, nunca un `success` falso.
 */
final class LegacySyncIncrementalStockCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-incremental-stock
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Calcula e informa el delta sin aplicar nada ni registrar la corrida}
        {--force : Aplica aunque se supere un umbral del circuit breaker (tras revisar el motivo)}
        {--operador= : Corrida manual supervisada: corre aunque `activo` esté apagado, origen=manual y queda en auditoría}';

    /** @var string */
    protected $description = 'SCRUM-1114 — sincroniza el stock de ICG (AL4) como delta sobre el stock de AtlanticERP, pensado para el Scheduler';

    public function __construct(
        private readonly StockAl4ExtractorContract $extractor,
        private readonly StockDeltaService $service,
        private readonly LegacySyncAlertService $alerts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        MemoryLimit::atLeast('512M');

        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * Un solo sync de stock (o reversa) a la vez por tenant (revisión de seguridad S-09): `withoutOverlapping()` del
     * scheduler no protege contra una corrida manual simultánea, y dos corridas calculando sobre la misma base
     * (aunque el compare-and-swap por producto lo contiene) duplicarían trabajo y ruido de auditoría.
     */
    private function ejecutar(string $slug): int
    {
        $lock = Cache::lock("legacy-sync-stock:{$slug}", 7200);
        if (! $lock->get()) {
            $this->error('Ya hay una corrida o reversa de stock en curso para este tenant: no se lanza otra en paralelo.');

            return self::FAILURE;
        }

        try {
            return $this->correr($slug);
        } finally {
            $lock->release();
        }
    }

    private function correr(string $slug): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        $operador = trim((string) $this->option('operador'));
        $manual   = $operador !== '';

        $settings = StockSyncSettings::current();

        // Primero el interruptor: mientras esté apagado y sin línea base, la cadena nocturna no debe
        // fallar cada noche por un paso que todavía nadie encendió.
        if (! $dryRun && ! $manual && ! $settings->activo) {
            $this->comment('Sincronización de stock desactivada (legacy.stock_sync_settings.activo = false) — no se ejecuta.');

            return self::SUCCESS;
        }

        if ($settings->baseline_inicializada_at === null) {
            // Se registra y se avisa (en vez de fallar en silencio): con `activo = true` y sin base, la cadena
            // nocturna no puede correr y alguien tiene que enterarse.
            return $this->fallar($dryRun, 'La línea base de stock no está inicializada: corra primero `legacy:init-stock-baseline` (con la base sin inicializar el delta sería el stock completo de ICG).', 0, now(), microtime(true), $manual);
        }

        $centralId = $this->service->bodegaCentralId();
        if ($centralId === null) {
            return $this->fallar($dryRun, 'Bodega Central no existe o no es operativa (solo_consulta): no hay dónde reflejar el stock.', 0, now(), microtime(true), $manual);
        }

        $startedAt = now();
        $start     = microtime(true);

        try {
            $al4 = $this->extractor->extract();
        } catch (Throwable $e) {
            return $this->fallar($dryRun, "Error al leer el stock de ICG: " . LegacySyncAlertService::sanitizar($e->getMessage()), 0, $startedAt, $start, $manual);
        }

        if ($al4 === []) {
            return $this->fallar($dryRun, 'ICG devolvió 0 filas de stock de AL4: se aborta para no interpretar un stock vacío como un delta masivo.', 0, $startedAt, $start, $manual);
        }

        try {
            $plan = $this->service->plan($al4);
        } catch (Throwable $e) {
            return $this->fallar($dryRun, "Error al calcular el delta: " . LegacySyncAlertService::sanitizar($e->getMessage()), count($al4), $startedAt, $start, $manual);
        }

        $resumen = $plan['resumen'];
        // Cuánto se aleja AtlanticERP de ICG (stock que AtlanticERP tiene o le falta por su cuenta); solo informa, no se corrige.
        $resumen['divergencia_atlanticerp'] = $this->service->divergenciaAtlanticERP($centralId);
        $this->informe($resumen, count($al4));

        if ($dryRun) {
            $this->comment('Modo --dry-run: no se aplicó nada ni se registró la corrida.');

            return self::SUCCESS;
        }

        $ultimaBuena = SyncRun::query()
            ->where('entidad', 'stock')
            ->whereIn('status', [SyncRun::STATUS_SUCCESS, SyncRun::STATUS_PARTIAL])
            ->where('extraidos', '>', 0)
            ->orderByDesc('id')
            ->value('extraidos');
        $motivo = $this->service->motivoRespuestaParcial(count($al4), $ultimaBuena === null ? null : (int) $ultimaBuena, $settings)
            ?? $this->service->motivoCircuitBreaker($resumen, $settings);
        if ($motivo !== null && ! $force) {
            $this->warn("Circuit breaker: {$motivo} — NO se aplica nada, queda en held_for_review.");
            $resumen['circuit_breaker'] = $motivo;

            $run = $this->registrar($manual, $startedAt, $start, count($al4), $resumen, 0, 0, SyncRun::STATUS_HELD_FOR_REVIEW);
            $this->auditar($slug, $operador, $run, $resumen, forzada: false);
            $this->alerts->alertHeldForReview('stock', $motivo, $this->comandoManual($slug));

            return self::SUCCESS;
        }
        if ($motivo !== null) {
            $resumen['circuit_breaker_forzado'] = $motivo;
            $this->warn("Se supera un umbral ({$motivo}) pero se aplica por --force.");
        }

        // Se registra primero: los movimientos de kardex referencian el id de la corrida.
        $run = $this->registrar($manual, $startedAt, $start, count($al4), $resumen, 0, 0, SyncRun::STATUS_FAILED);

        try {
            $res = $this->service->apply($plan, (int) $run->id, $centralId);
        } catch (Throwable $e) {
            return $this->fallar(false, "Error al aplicar el delta: " . LegacySyncAlertService::sanitizar($e->getMessage()), count($al4), $startedAt, $start, $manual, $run);
        }

        // La divergencia se recalcula DESPUÉS de aplicar: la diferencia es la misma, pero el informe guardado debe
        // mostrar los valores finales de AtlanticERP e ICG y no los de antes del delta.
        $resumen = array_merge($resumen, ['aplicado' => $res], $this->service->verificarCuadre(), ['divergencia_atlanticerp' => $this->service->divergenciaAtlanticERP($centralId)]);
        $status  = $res['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $run->update([
            'cargados'    => $res['aplicados'],
            'errores'     => $res['errores'],
            'status'      => $status,
            'resumen'     => $resumen,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'finished_at' => now(),
        ]);
        $this->auditar($slug, $operador, $run, $resumen, forzada: $motivo !== null);

        $this->info(sprintf(
            'Aplicados: %d | Bases inicializadas: %d | Cambiados por otra corrida: %d | Errores: %d | Quedaron en negativo: %d | No cuadran (bodega vs stock_quantity): %d',
            $res['aplicados'], $res['bases_inicializadas'], $res['omitidos_cambiados'], $res['errores'], $res['negativos'], $resumen['no_cuadran'],
        ));

        if ($status === SyncRun::STATUS_SUCCESS) {
            $this->alerts->publishDigest($run);
        } else {
            $this->alerts->alertFailed('stock', "{$res['errores']} producto(s) con error: " . implode(' | ', array_slice($res['mensajes_error'], 0, 3)));
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $resumen */
    private function informe(array $resumen, int $filasIcg): void
    {
        $this->info('legacy:sync-incremental-stock — informe de divergencia');
        $this->table(['Concepto', 'Valor'], [
            ['Filas de AL4 leídas de ICG', $filasIcg],
            ['Productos elegibles (ligados, activos, no descatalogados)', $resumen['elegibles']],
            ['Omitidos (inactivos o descatalogados)', $resumen['omitidos_inactivos']],
            ['Sin cambio', $resumen['sin_cambio']],
            ['Con delta', $resumen['con_delta']],
            ['  de los cuales parten de 0 (sin stock en AtlanticERP)', $resumen['desde_cero']],
            ['Unidades positivas (entradas en ICG)', $resumen['unidades_positivas']],
            ['Unidades negativas (salidas en ICG)', $resumen['unidades_negativas']],
            ['Mayor delta absoluto en un producto', $resumen['max_abs_producto']],
            ['Bases por inicializar a 0 (sin movimiento)', $resumen['base_inicializada']],
            ['Retenidos (sin base y con stock en AtlanticERP)', $resumen['retenidos']],
            ['Elegibles sin fila en AL4 (se toman como 0)', $resumen['sin_fila_al4']],
        ]);

        $d = $resumen['divergencia_atlanticerp'];
        $this->info('Divergencia de AtlanticERP respecto de ICG en Bodega Central (AtlanticERP manda: solo se informa)');
        $this->table(['Concepto', 'Valor'], [
            ['Productos donde AtlanticERP difiere de ICG', $d['total']],
            ['  AtlanticERP tiene más (unidades de más)', "{$d['atlanticerp_con_mas']} (+{$d['unidades_mas']})"],
            ['  AtlanticERP tiene menos (unidades de menos)', "{$d['atlanticerp_con_menos']} ({$d['unidades_menos']})"],
            ['  Explicados por el kardex de AtlanticERP (la suma de sus movimientos iguala la diferencia)', $d['explicados_por_kardex']],
            ['  Con movimientos de AtlanticERP que NO alcanzan a explicarla', $d['con_residuo']],
            ['  Sin ningún movimiento de kardex de AtlanticERP (origen desconocido)', $d['sin_movimientos']],
            ['Unidades que quedan sin explicar', $d['unidades_sin_explicar']],
        ]);
    }

    /** @param array<string, mixed> $resumen */
    private function registrar(bool $manual, Carbon $startedAt, float $start, int $extraidos, array $resumen, int $cargados, int $errores, string $status): SyncRun
    {
        return SyncRun::query()->create([
            'entidad'     => 'stock',
            'origen'      => $manual ? SyncRun::ORIGEN_MANUAL : SyncRun::ORIGEN_SCHEDULER,
            'extraidos'   => $extraidos,
            'staged'      => (int) $resumen['elegibles'],
            'mapeados'    => (int) $resumen['con_delta'],
            'cargados'    => $cargados,
            'excluidos'   => (int) $resumen['omitidos_inactivos'] + (int) $resumen['retenidos'],
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'resumen'     => $resumen,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }

    /** Falla la corrida: la registra (salvo `--dry-run`), avisa por correo y devuelve FAILURE. */
    private function fallar(bool $dryRun, string $motivo, int $extraidos, Carbon $startedAt, float $start, bool $manual, ?SyncRun $run = null): int
    {
        $this->error($motivo);
        if ($dryRun) {
            return self::FAILURE;
        }

        $vacio = ['elegibles' => 0, 'con_delta' => 0, 'omitidos_inactivos' => 0, 'retenidos' => 0, 'error' => $motivo];
        if ($run === null) {
            $this->registrar($manual, $startedAt, $start, $extraidos, $vacio, 0, 1, SyncRun::STATUS_FAILED);
        } else {
            $run->update(['errores' => 1, 'status' => SyncRun::STATUS_FAILED, 'resumen' => array_merge($run->resumen ?? [], ['error' => $motivo]), 'finished_at' => now()]);
        }
        $this->alerts->alertFailed('stock', $motivo);

        return self::FAILURE;
    }

    /** @param array<string, mixed> $resumen */
    private function auditar(string $slug, string $operador, SyncRun $run, array $resumen, bool $forzada): void
    {
        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_SYNC_STOCK_DELTA,
            'entity_type' => 'sync_run',
            'entity_id'   => $run->id,
            'metadata'    => OperatorContext::describe($operador) + [
                'origen'             => $run->origen,
                'entorno'            => (string) config('app.env'),
                'tenant'             => $slug,
                'status'             => $run->status,
                'forzada'            => $forzada,
                'con_delta'          => $resumen['con_delta'],
                'unidades_positivas' => $resumen['unidades_positivas'],
                'unidades_negativas' => $resumen['unidades_negativas'],
                'retenidos'          => $resumen['retenidos'],
                'circuit_breaker'    => $resumen['circuit_breaker'] ?? null,
            ],
        ]);

        // Una corrida lanzada a mano o forzada se notifica siempre (S-04): `--operador` es texto libre.
        if ($run->origen === SyncRun::ORIGEN_MANUAL || $forzada) {
            $ctx = OperatorContext::describe($operador);
            $this->alerts->alertEvento('stock', 'Corrida de stock lanzada a mano', [
                "Corrida #{$run->id}: {$run->status}" . ($forzada ? ' (forzada con --force)' : ''),
                OperatorContext::linea($ctx),
                "Con delta: {$resumen['con_delta']} · unidades +{$resumen['unidades_positivas']} / {$resumen['unidades_negativas']}",
            ]);
        }
    }

    private function comandoManual(string $slug): string
    {
        return "php artisan legacy:sync-incremental-stock --tenant={$slug} --dry-run (revisar) y luego --operador=\"<nombre>\" --force";
    }
}
