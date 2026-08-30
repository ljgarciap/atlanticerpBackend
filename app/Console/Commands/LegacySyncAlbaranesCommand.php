<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\AlbaranMatcher;
use App\Modules\Legacy\Services\AlbaranStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-759 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging)→Matcher para el batch de Albaranes (Illumination/migracion-icg-diseno.md §5). Igual
 * que `LegacySyncPedidosCommand` (SCRUM-758), esta entidad NO tiene Loader-B — el `AlbaranMatcher`
 * persiste el resultado directamente en `legacy.albaranes` (ver runbook §12.1). El Matcher acá es
 * un LOOKUP simple contra `legacy.clientes` ya poblada — no re-matchea por CIF/email.
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Matcher — TODOS escriben exclusivamente
 * dentro de `legacy.*`, upserts/replace idempotentes sobre tablas de solo-lectura de referencia,
 * así que correrlos en modo rehearsal es seguro (mismo criterio que `LegacySyncPedidosCommand`).
 * Lo único que NUNCA pasa en dry-run es el registro de la corrida en `legacy.sync_runs`.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (mismo criterio que
 * `LegacySyncPedidosCommand`): como Albaranes tampoco tiene una etapa de "carga a un destino"
 * separada del matching (el Matcher resuelve Y persiste en el mismo paso, dentro de
 * `legacy.albaranes`), `mapeados` y `cargados` quedan ambos iguales al conteo de `matched`.
 * `excluidos` = `orphan` (albaranes que no terminaron con un `legacy_cliente_id` resuelto).
 *
 * El link a la factura (`icg_numseriefac`/`icg_numfac`) queda crudo y sin resolver — fuera de
 * alcance de este batch, se resuelve cuando se implemente Facturas (SCRUM-760, otro ticket).
 *
 * ITEMS PAGINADOS DESDE 2026-08-27 — la primera corrida real contra `dev.atlanticerp.ai` reventó por OOM
 * del kernel apenas arrancó (mismo síntoma que Cotizaciones el 2026-08-21, ver
 * `Illumination/migracion-icg-diseno.md` §13.3): ALBVENTALIN (~199,766 filas) extraído entero de
 * una sola vez no entraba en la memoria real disponible del host. Este comando ya no pide TODOS los
 * items al Extractor — usa `AlbaranExtractorContract::extractItemsPage()` a través de
 * `AlbaranStagingLoader::loadPaginated()`, que pagina la extracción y el staging juntos (nunca más
 * de una página de items en memoria a la vez). Las cabeceras (~60,985, volumen mucho menor) se
 * siguen trayendo completas.
 */
final class LegacySyncAlbaranesCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-albaranes
        {--tenant=illuminations : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-759 — extrae ALBVENTACAB/ALBVENTALIN de ICG, stagea en legacy.albaranes/albaran_items y resuelve legacy_cliente_id por lookup contra legacy.clientes';

    public function __construct(
        private readonly AlbaranExtractorContract $extractor,
        private readonly AlbaranStagingLoader $stagingLoader,
        private readonly AlbaranMatcher $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncClientesCommand/LegacySyncCotizacionesCommand (§13.3): el
        // memory_limit default de PHP CLI (128M) no alcanza ni para las ~60,985 cabeceras de
        // ALBVENTACAB solas. ini_set() aplica directo porque este comando ES el proceso PHP
        // top-level. Items (ALBVENTALIN, ~199,766 filas) ya NO se traen enteros de una — ver
        // docblock de la clase, "ITEMS PAGINADOS" — 512M sigue siendo margen de sobra para
        // cabeceras + una página de items a la vez, muy lejos del límite real del host que causó
        // el OOM real cuando SÍ se traían todos los items juntos.
        ini_set('memory_limit', '512M');

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

        // Mismo patrón que LegacySyncPedidosCommand (hallazgo Pre-QA SCRUM-750): un segundo
        // try/catch alrededor de staging+matching — sin esto, cualquier falla acá dejaba la
        // corrida sin rastro en legacy.sync_runs.
        try {
            return $this->stageAndMatch($albaranes, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear albaranes: {$e->getMessage()}");

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

    /** @param list<AlbaranRow> $albaranes */
    private function stageAndMatch(array $albaranes, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        // Cuenta lo realmente extraído de ALBVENTALIN a medida que llegan las páginas — sin esto no
        // hay forma barata de mostrar "Extraídos" distinto de "Staged" en el reporte, ya que el
        // Extractor nunca entrega el total de items de una — ver docblock de la clase.
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

        $this->recordRun(
            $startedAt, $start,
            extraidos: count($albaranes),
            staged: $stagingCounts['albaranes'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * @param array{albaranes: int, items: int, albaran_ids: list<int>} $stagingCounts
     * @param array{matched: int, orphan: int, errores: int}            $matchResult
     */
    private function renderReport(int $albaranesExtraidos, int $itemsExtraidos, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-albaranes — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (ALBVENTACAB)', $albaranesExtraidos],
            ['Extraídos (ALBVENTALIN)', $itemsExtraidos],
            ['Staged — albaranes', $stagingCounts['albaranes']],
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
    ): void {
        SyncRun::query()->create([
            'entidad'     => 'albaranes',
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
