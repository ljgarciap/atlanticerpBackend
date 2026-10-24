<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\PagoIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-pagos`. Pagos no tiene Matcher
 * ni Loader-B (ver docblock de PagoIncrementalStagingLoader) — el hash-compare del staging paginado
 * ES el ahorro completo para esta entidad, no hay un paso caro posterior que saltar.
 *
 * CIRCUIT BREAKER: baseline = filas YA existentes en legacy.pagos ANTES de esta corrida (nunca el
 * total de esta misma corrida). Acá el circuit breaker no evita un paso caro adicional (no existe),
 * pero sigue siendo la señal de "algo raro pasó en ICG" que el ticket pide — si el volumen de
 * cambio es anormal, igual vale la pena frenar y avisar antes de asumir que los datos son
 * confiables, aunque "frenar" acá signifique no re-evaluar el resto del reporte, no bloquear un
 * write costoso (el staging ya corrió con hash-compare para cuando se calcula el ratio).
 */
final class LegacySyncIncrementalPagosCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-pagos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare) y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-pagos, pensada para el Scheduler';

    public function __construct(
        private readonly PagoExtractorContract $extractor,
        private readonly PagoIncrementalStagingLoader $stagingLoader,
        private readonly LegacySyncAlertService $alerts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        MemoryLimit::atLeast('512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $startedAt = now();
        $start     = microtime(true);

        $tenant->makeCurrent();
        try {
            return $this->runPipeline($isDryRun, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runPipeline(bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Pago::query()->count();

        $extracted   = 0;
        $pageFetcher = function (int $offset, int $limit) use (&$extracted): array {
            $page = $this->extractor->extractPage($offset, $limit);
            $extracted += count($page);

            return $page;
        };

        try {
            $stagingCounts = $this->stagingLoader->loadPaginated($pageFetcher);
        } catch (Throwable $e) {
            $this->error("Error al extraer/stagear pagos: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: $extracted, staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }

        $totalCambiados = $stagingCounts['nuevos'] + $stagingCounts['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-pagos — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['pagos', $stagingCounts['total'], $stagingCounts['nuevos'], $stagingCounts['cambiados'], $stagingCounts['sin_cambios']],
        ]);

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging (legacy.pagos) actualizado, no se registró la corrida.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf(
                'Circuit breaker: %.1f%% de pagos cambiaron en esta corrida (umbral %.0f%%) — se marca held_for_review para revisión manual.',
                $changeRatio * 100,
                self::CHANGE_THRESHOLD * 100,
            ));

            Log::warning('legacy:sync-incremental-pagos — circuit breaker disparado', [
                'entidad'         => 'pagos',
                'total_extraidos' => $extracted,
                'total_cambiados' => $totalCambiados,
                'change_ratio'    => $changeRatio,
                'threshold'       => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun(
                $startedAt, $start,
                extraidos: $extracted,
                staged: $stagingCounts['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_HELD_FOR_REVIEW,
            );

            return self::SUCCESS;
        }

        // mapeados/cargados = solo lo que cambió de verdad (nuevos+cambiados), NO el total
        // staged — a diferencia del full-sync (donde todo se re-escribe siempre y "mapeados"
        // legítimamente es el total), acá "sin_cambios" nunca tocó Postgres, así que contarlo
        // como "cargado" sería engañoso (mismo criterio ya corregido en Productos).
        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $extracted,
            staged: $stagingCounts['total'],
            mapeados: $totalCambiados,
            cargados: $totalCambiados,
            excluidos: 0,
            errores: 0,
            status: SyncRun::STATUS_SUCCESS,
        );
        $this->alerts->publishDigest($syncRun);

        return self::SUCCESS;
    }

    private function recordRun(
        Carbon $startedAt,
        float $start,
        int $extraidos,
        int $staged,
        int $mapeados,
        int $cargados,
        int $excluidos,
        int $errores,
        string $status,
    ): SyncRun {
        return SyncRun::query()->create([
            'entidad'     => 'pagos',
            'origen'      => SyncRun::ORIGEN_SCHEDULER,
            'extraidos'   => $extraidos,
            'staged'      => $staged,
            'mapeados'    => $mapeados,
            'cargados'    => $cargados,
            'excluidos'   => $excluidos,
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
