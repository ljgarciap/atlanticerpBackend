<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\CotizacionMatcher;
use App\Modules\Legacy\Services\CotizacionStagingLoader;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-757 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging)→Matcher para el batch de Cotizaciones (Illumination/migracion-icg-diseno.md §5). Igual
 * que `LegacySyncClientesCommand` (SCRUM-755), esta entidad NO tiene Loader-B — el
 * `CotizacionMatcher` persiste el resultado directamente en `legacy.cotizaciones` (ver runbook
 * §12.1). A diferencia de Clientes, el Matcher acá es un LOOKUP simple contra `legacy.clientes` ya
 * poblada — no re-matchea por CIF/email.
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Matcher — TODOS escriben exclusivamente
 * dentro de `legacy.*`, upserts/replace idempotentes sobre tablas de solo-lectura de referencia,
 * así que correrlos en modo rehearsal es seguro (mismo criterio que `LegacySyncClientesCommand`).
 * Lo único que NUNCA pasa en dry-run es el registro de la corrida en `legacy.sync_runs`.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (mismo criterio que
 * `LegacySyncClientesCommand`): como Cotizaciones tampoco tiene una etapa de "carga a un destino"
 * separada del matching (el Matcher resuelve Y persiste en el mismo paso, dentro de
 * `legacy.cotizaciones`), `mapeados` y `cargados` quedan ambos iguales al conteo de `matched`.
 * `excluidos` = `orphan` (cotizaciones que no terminaron con un `legacy_cliente_id` resuelto).
 *
 * ITEMS PAGINADOS DESDE 2026-08-21 — la primera corrida real contra `dev.atlanticerp.ai` (SCRUM-757, ver
 * `Illumination/migracion-icg-diseno.md` §13.3) reventó por OOM del kernel: PRESUPUESTOSLIN
 * (715,530 filas) extraído entero de una sola vez no entraba en la memoria real disponible del
 * host. Este comando ya no pide TODOS los items al Extractor — usa `CotizacionExtractorContract::
 * extractItemsPage()` a través de `CotizacionStagingLoader::loadPaginated()`, que pagina la
 * extracción y el staging juntos (nunca más de una página de items en memoria a la vez). Las
 * cabeceras (94,823, ~8x Clientes que ya corrió sin problema) se siguen trayendo completas.
 */
final class LegacySyncCotizacionesCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-cotizaciones
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-757 — extrae PRESUPUESTOSCAB/PRESUPUESTOSLIN de ICG, stagea en legacy.cotizaciones/cotizacion_items y resuelve legacy_cliente_id por lookup contra legacy.clientes';

    public function __construct(
        private readonly CotizacionExtractorContract $extractor,
        private readonly CotizacionStagingLoader $stagingLoader,
        private readonly CotizacionMatcher $matcher,
        private readonly SyncRunDetailRecorder $detailRecorder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncClientesCommand/LegacySyncProductosCommand (§13.3): el
        // memory_limit default de PHP CLI (128M) no alcanza ni para las 94,823 cabeceras de
        // PRESUPUESTOSCAB solas. ini_set() aplica directo porque este comando ES el proceso PHP
        // top-level. Items (PRESUPUESTOSLIN, 715,530 filas) ya NO se traen enteros de una — ver
        // docblock de la clase, "ITEMS PAGINADOS" — 512M sigue siendo margen de sobra para
        // cabeceras + una página de items a la vez, muy lejos del límite real del host que causó
        // el OOM real cuando SÍ se traían todos los items juntos.
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
            $cotizaciones = $this->extractor->extractCotizaciones();
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

        // Mismo patrón que LegacySyncClientesCommand (hallazgo Pre-QA SCRUM-750): un segundo
        // try/catch alrededor de staging+matching — sin esto, cualquier falla acá dejaba la
        // corrida sin rastro en legacy.sync_runs.
        try {
            return $this->stageAndMatch($cotizaciones, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear cotizaciones: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($cotizaciones), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    /** @param list<CotizacionRow> $cotizaciones */
    private function stageAndMatch(array $cotizaciones, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        // Cuenta lo realmente extraído de PRESUPUESTOSLIN a medida que llegan las páginas — sin
        // esto no hay forma barata de mostrar "Extraídos" distinto de "Staged" en el reporte, ya
        // que el Extractor nunca entrega el total de items de una — ver docblock de la clase.
        $itemsExtracted = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingCounts = $this->stagingLoader->loadPaginated($cotizaciones, $itemPageFetcher);
        $matchResult   = $this->matcher->match($stagingCounts['cotizacion_ids']);

        $this->renderReport(count($cotizaciones), $itemsExtracted, $stagingCounts, $matchResult);

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
            extraidos: count($cotizaciones),
            staged: $stagingCounts['cotizaciones'],
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
     * @param array{cotizaciones: int, items: int, cotizacion_ids: list<int>} $stagingCounts
     * @param array{matched: int, orphan: int, errores: int, details: list<array{referencia: string, status: string, motivo: string}>} $matchResult
     */
    private function renderReport(int $cotizacionesExtraidas, int $itemsExtraidos, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-cotizaciones — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (PRESUPUESTOSCAB)', $cotizacionesExtraidas],
            ['Extraídos (PRESUPUESTOSLIN)', $itemsExtraidos],
            ['Staged — cotizaciones', $stagingCounts['cotizaciones']],
            ['Staged — items', $stagingCounts['items']],
            ['Matched (legacy_cliente_id resuelto)', $matchResult['matched']],
            ['Orphan (icg_codcliente sin match en legacy.clientes)', $matchResult['orphan']],
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
            'entidad'     => 'cotizaciones',
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
