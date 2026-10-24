<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\ProveedorIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-proveedores`. Proveedores es la
 * entidad más simple: sin Matcher ni Loader-B (ver docblock de `LegacySyncProveedoresCommand`), así
 * que a diferencia de Productos/Clientes acá no hay un "paso caro" separado que filtrar — el
 * staging hash-compare de `ProveedorIncrementalStagingLoader` ya es el ahorro completo.
 *
 * Circuit breaker: mismo criterio que Productos/Clientes — baseline = filas YA EXISTENTES en
 * `legacy.proveedores` ANTES de esta corrida (nunca el total extraído de esta misma corrida).
 */
final class LegacySyncIncrementalProveedoresCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-proveedores
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare) y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-proveedores, pensada para el Scheduler';

    public function __construct(
        private readonly ProveedorExtractorContract $extractor,
        private readonly ProveedorIncrementalStagingLoader $stagingLoader,
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
        try {
            $extracted = $this->extractor->extract();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: 0, staged: 0, cargados: 0, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        try {
            return $this->stage($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear proveedores: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($extracted->proveedores), staged: 0, cargados: 0, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    private function stage(ExtractedProveedores $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Proveedor::query()->count();

        $stagingCounts  = $this->stagingLoader->load($extracted);
        $totalExtraidos = count($extracted->proveedores);
        $totalCambiados = $stagingCounts['nuevos'] + $stagingCounts['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-proveedores — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['proveedores', $stagingCounts['total'], $stagingCounts['nuevos'], $stagingCounts['cambiados'], $stagingCounts['sin_cambios']],
        ]);

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging (legacy.proveedores) actualizado, no se evalúa el circuit breaker ni se registra la corrida.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf(
                'Circuit breaker: %.1f%% de proveedores cambiaron en esta corrida (umbral %.0f%%) — queda en held_for_review.',
                $changeRatio * 100,
                self::CHANGE_THRESHOLD * 100,
            ));

            Log::warning('legacy:sync-incremental-proveedores — circuit breaker disparado', [
                'entidad'         => 'proveedores',
                'total_extraidos' => $totalExtraidos,
                'total_cambiados' => $totalCambiados,
                'change_ratio'    => $changeRatio,
                'threshold'       => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingCounts['total'], cargados: 0, status: SyncRun::STATUS_HELD_FOR_REVIEW);

            return self::SUCCESS;
        }

        $syncRun = $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingCounts['total'], cargados: $totalCambiados, status: SyncRun::STATUS_SUCCESS);
        $this->alerts->publishDigest($syncRun);

        return self::SUCCESS;
    }

    private function recordRun(Carbon $startedAt, float $start, int $extraidos, int $staged, int $cargados, string $status): SyncRun
    {
        // SCRUM-922 (fix 2026-09-20) — sin Matcher/Loader-B propios, mapeados/cargados quedan
        // ambos iguales al DELTA real (nuevos+cambiados, ver stage()), nunca al total staged/
        // extraído: antes de este fix una corrida sin cambios reales reportaba el 100% del
        // catálogo como "cargado" (bug de reporte puro, el upsert hash-compare siempre funcionó
        // bien). excluidos siempre 0 (proveedores no tiene Matcher/criterio de exclusión).
        return SyncRun::query()->create([
            'entidad'     => 'proveedores',
            'origen'      => SyncRun::ORIGEN_SCHEDULER,
            'extraidos'   => $extraidos,
            'staged'      => $staged,
            'mapeados'    => $cargados,
            'cargados'    => $cargados,
            'excluidos'   => 0,
            'errores'     => 0,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
