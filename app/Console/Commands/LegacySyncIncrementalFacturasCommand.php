<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\FacturaIncrementalStagingLoader;
use App\Modules\Legacy\Services\FacturaMatcher;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-facturas` (que sigue intacto
 * para carga inicial/reconciliación manual vía infra/legacy-sync.sh --force). Mismo patrón que
 * LegacySyncIncrementalProductosCommand:
 *  - FacturaIncrementalStagingLoader (hash-compare vía source_hash) en vez de FacturaStagingLoader.
 *  - CIRCUIT BREAKER: baseline = filas YA existentes en legacy.facturas ANTES de esta corrida
 *    (nunca el total de esta misma corrida — bug real ya corregido en Productos, mismo criterio
 *    acá). Sin baseline (carga inicial) nunca se evalúa.
 *  - El "paso caro" post-staging es FacturaMatcher — solo corre sobre los `id` que
 *    FacturaIncrementalStagingLoader reporta como realmente cambiados (nuevos o con hash
 *    distinto), nunca sobre todas las facturas extraídas (mismo fix aplicado a Productos: el
 *    ahorro real es que el paso caro ni siquiera corra para lo que no cambió).
 *  - `sync_runs.origen = 'scheduler'`.
 *
 * PENDIENTE — SNS real para el circuit breaker: mismo motivo documentado en
 * LegacySyncIncrementalProductosCommand (sin cliente SNS/permiso IAM en la app hoy) — Log::warning
 * interino, ya visible en CloudWatch vía el agente existente.
 */
final class LegacySyncIncrementalFacturasCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-facturas
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare) y reporta conteos, sin matchear ni registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-facturas, pensada para el Scheduler';

    public function __construct(
        private readonly FacturaExtractorContract $extractor,
        private readonly FacturaIncrementalStagingLoader $stagingLoader,
        private readonly FacturaMatcher $matcher,
        private readonly SyncRunDetailRecorder $detailRecorder,
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
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }

        try {
            return $this->stageAndMatch($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear facturas: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($extracted->facturas), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    private function stageAndMatch(ExtractedFacturas $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Factura::query()->count();

        $stagingCounts  = $this->stagingLoader->load($extracted);
        $totalExtraidos = count($extracted->facturas);
        $totalCambiados = $stagingCounts['facturas']['nuevos'] + $stagingCounts['facturas']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-facturas — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['facturas', $stagingCounts['facturas']['total'], $stagingCounts['facturas']['nuevos'], $stagingCounts['facturas']['cambiados'], $stagingCounts['facturas']['sin_cambios']],
        ]);

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging (legacy.facturas) actualizado, no se evalúa el circuit breaker ni se matchea.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf(
                'Circuit breaker: %.1f%% de facturas cambiaron en esta corrida (umbral %.0f%%) — NO se matchea, queda en held_for_review.',
                $changeRatio * 100,
                self::CHANGE_THRESHOLD * 100,
            ));

            Log::warning('legacy:sync-incremental-facturas — circuit breaker disparado', [
                'entidad'         => 'facturas',
                'total_extraidos' => $totalExtraidos,
                'total_cambiados' => $totalCambiados,
                'change_ratio'    => $changeRatio,
                'threshold'       => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['facturas']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_HELD_FOR_REVIEW,
            );

            return self::SUCCESS;
        }

        $changedIds = $stagingCounts['changed_ids'];

        if ($changedIds === []) {
            $this->comment('Ninguna factura cambió — no se ejecuta el Matcher.');

            $syncRun = $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['facturas']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_SUCCESS,
            );
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $matchResult = $this->matcher->match($changedIds);
        $status      = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingCounts['facturas']['total'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        $this->detailRecorder->record($syncRun, $matchResult['details']);

        if ($status === SyncRun::STATUS_SUCCESS) {
            $this->alerts->publishDigest($syncRun);
        }

        $this->info(sprintf('Matched: %d | Orphan: %d | Errores: %d', $matchResult['matched'], $matchResult['orphan'], $matchResult['errores']));

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
            'entidad'     => 'facturas',
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
