<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteIncrementalStagingLoader;
use App\Modules\Legacy\Services\ClienteMatcher;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-clientes` (que sigue intacto
 * para carga inicial/reconciliación manual vía infra/legacy-sync.sh --force). Mismo diseño que
 * `LegacySyncIncrementalProductosCommand` (leer ese docblock primero, es la plantilla real):
 *
 *  - Usa `ClienteIncrementalStagingLoader` (hash-compare) en vez de `ClienteStagingLoader`.
 *  - CIRCUIT BREAKER: baseline = filas YA EXISTENTES en `legacy.clientes` ANTES de esta corrida
 *    (nunca el total extraído de esta misma corrida — mismo bug ya corregido una vez en
 *    Productos, no repetirlo). Sin baseline (carga inicial) nunca se evalúa.
 *  - El paso caro de esta entidad es `ClienteMatcher::match()` (reconstruye 2 índices completos
 *    de `ventas_diseno.sub_clients`/`sub_client_contacts` y recorre cada cliente) — solo corre
 *    sobre los `icg_codcliente` que cambiaron de verdad (`changed_codclientes` del staging
 *    loader), nunca sobre todos los extraídos. Los contactos NO alimentan al Matcher (que solo
 *    lee columnas de la propia `clientes`), así que un contacto nuevo/cambiado sin que su cliente
 *    dueño cambie no dispara un re-match — correcto, el Matcher no tendría nada nuevo que evaluar.
 *  - `sync_runs.origen = 'scheduler'`.
 *
 * PENDIENTE — SNS real para el circuit breaker: mismo pendiente documentado en
 * LegacySyncIncrementalProductosCommand — Log::warning() interino hasta que exista un cliente
 * SNS/permiso IAM en la app.
 */
final class LegacySyncIncrementalClientesCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-clientes
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Matcher y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-clientes, pensada para el Scheduler';

    public function __construct(
        private readonly ClienteExtractorContract $extractor,
        private readonly ClienteIncrementalStagingLoader $stagingLoader,
        private readonly ClienteMatcher $matcher,
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
            $this->error("Error al stagear/matchear clientes: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($extracted->clientes), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    private function stageAndMatch(ExtractedClientes $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Cliente::query()->count();

        $stagingCounts  = $this->stagingLoader->load($extracted);
        $totalExtraidos = count($extracted->clientes);
        $totalCambiados = $stagingCounts['clientes']['nuevos'] + $stagingCounts['clientes']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-clientes — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['clientes', $stagingCounts['clientes']['total'], $stagingCounts['clientes']['nuevos'], $stagingCounts['clientes']['cambiados'], $stagingCounts['clientes']['sin_cambios']],
            ['contactos_cliente', $stagingCounts['contactos']['total'], $stagingCounts['contactos']['nuevos'], $stagingCounts['contactos']['cambiados'], $stagingCounts['contactos']['sin_cambios']],
        ]);

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging (legacy.*) actualizado, no se evalúa el circuit breaker ni se corre el Matcher.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf(
                'Circuit breaker: %.1f%% de clientes cambiaron en esta corrida (umbral %.0f%%) — NO se corre el Matcher, queda en held_for_review.',
                $changeRatio * 100,
                self::CHANGE_THRESHOLD * 100,
            ));

            Log::warning('legacy:sync-incremental-clientes — circuit breaker disparado', [
                'entidad'         => 'clientes',
                'total_extraidos' => $totalExtraidos,
                'total_cambiados' => $totalCambiados,
                'change_ratio'    => $changeRatio,
                'threshold'       => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['clientes']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_HELD_FOR_REVIEW,
            );

            return self::SUCCESS;
        }

        $codigos = $stagingCounts['changed_codclientes'];

        if ($codigos === []) {
            $this->comment('Ningún cliente cambió — no se ejecuta el Matcher.');

            $syncRun = $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['clientes']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_SUCCESS,
            );
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $matchResult = $this->matcher->match($codigos);
        $status      = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingCounts['clientes']['total'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['unmatched'] + $matchResult['ambiguous'],
            errores: $matchResult['errores'],
            status: $status,
        );

        $auditables = array_values(array_filter(
            $matchResult['details'],
            static fn (array $d): bool => $d['match_status'] !== 'matched',
        ));

        $this->detailRecorder->record($syncRun, array_map(
            static fn (array $d): array => [
                'referencia' => $d['cif'] ?? $d['email'] ?? (string) $d['icg_codcliente'],
                'status'     => $d['match_status'],
                'motivo'     => $d['motivo'],
            ],
            $auditables,
        ));

        if ($status === SyncRun::STATUS_SUCCESS) {
            $this->alerts->publishDigest($syncRun);
        }

        $this->info(sprintf('Matched: %d | Unmatched: %d | Ambiguous: %d | Errores: %d', $matchResult['matched'], $matchResult['unmatched'], $matchResult['ambiguous'], $matchResult['errores']));

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
            'entidad'     => 'clientes',
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
