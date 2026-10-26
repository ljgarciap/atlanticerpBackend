<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\AlbaranCompraMatcher;
use App\Modules\Legacy\Services\AlbaranCompraStagingLoader;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-840 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline Extractor→Loader-A→Matcher para
 * Albaranes de Compra — mismo patrón exacto que `LegacySyncAlbaranesCommand` (SCRUM-759), vinculando
 * a Proveedores en vez de Clientes. Sin Loader-B: el Matcher persiste directo en
 * `legacy.albaranes_compra`.
 *
 * `mapeados`/`cargados` en `legacy.sync_runs` quedan iguales al conteo de `matched` (mismo criterio
 * que Albaranes de Venta — no hay una etapa de carga separada del matching).
 */
final class LegacySyncAlbaranesCompraCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-albaranes-compra
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-840 — extrae ALBCOMPRACAB/ALBCOMPRALIN de ICG, stagea en legacy.albaranes_compra/albaran_compra_items y resuelve legacy_proveedor_id por lookup contra legacy.proveedores';

    public function __construct(
        private readonly AlbaranCompraExtractorContract $extractor,
        private readonly AlbaranCompraStagingLoader $stagingLoader,
        private readonly AlbaranCompraMatcher $matcher,
        private readonly SyncRunDetailRecorder $detailRecorder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que el resto del módulo (§13.3 del diseño) — memory_limit default de PHP
        // CLI no alcanza para el volumen real de esta entidad.
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
            $albaranes = $this->extractor->extractAlbaranes();
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
            return $this->stageAndMatch($albaranes, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear albaranes de compra: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($albaranes), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    /** @param list<AlbaranCompraRow> $albaranes */
    private function stageAndMatch(array $albaranes, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $itemsExtracted  = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingCounts = $this->stagingLoader->loadPaginated($albaranes, $itemPageFetcher);
        $matchResult   = $this->matcher->match($stagingCounts['albaran_ids']);

        $this->renderReport(count($albaranes), $itemsExtracted, $stagingCounts, $matchResult);

        if ($isDryRun) {
            $this->newLine();
            $this->comment(
                'Modo --dry-run: staging y matching (legacy.*) actualizados, pero NO se registró '
                . 'la corrida en legacy.sync_runs.'
            );

            return self::SUCCESS;
        }

        $status = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: count($albaranes),
            staged: $stagingCounts['albaranes'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        $this->detailRecorder->record($syncRun, $matchResult['details']);

        return self::SUCCESS;
    }

    /**
     * @param array{albaranes: int, items: int, albaran_ids: list<int>} $stagingCounts
     * @param array{matched: int, orphan: int, errores: int, details: list<array{referencia: string, status: string, motivo: string}>} $matchResult
     */
    private function renderReport(int $albaranesExtraidos, int $itemsExtraidos, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-albaranes-compra — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (ALBCOMPRACAB)', $albaranesExtraidos],
            ['Extraídos (ALBCOMPRALIN)', $itemsExtraidos],
            ['Staged — albaranes de compra', $stagingCounts['albaranes']],
            ['Staged — items', $stagingCounts['items']],
            ['Matched (legacy_proveedor_id resuelto)', $matchResult['matched']],
            ['Orphan (icg_codproveedor sin match en legacy.proveedores)', $matchResult['orphan']],
            ['Errores', $matchResult['errores']],
        ]);
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
            'entidad'     => 'albaranes_compra',
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
