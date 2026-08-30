<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\PedidoMatcher;
use App\Modules\Legacy\Services\PedidoStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-758 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging)→Matcher para el batch de Pedidos (Illumination/migracion-icg-diseno.md §5). Igual que
 * `LegacySyncCotizacionesCommand` (SCRUM-757), esta entidad NO tiene Loader-B — el `PedidoMatcher`
 * persiste el resultado directamente en `legacy.pedidos` (ver runbook §12.1). El Matcher acá es un
 * LOOKUP simple contra `legacy.clientes` ya poblada — no re-matchea por CIF/email.
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Matcher — TODOS escriben exclusivamente
 * dentro de `legacy.*`, upserts/replace idempotentes sobre tablas de solo-lectura de referencia,
 * así que correrlos en modo rehearsal es seguro (mismo criterio que `LegacySyncCotizacionesCommand`).
 * Lo único que NUNCA pasa en dry-run es el registro de la corrida en `legacy.sync_runs`.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (mismo criterio que
 * `LegacySyncCotizacionesCommand`): como Pedidos tampoco tiene una etapa de "carga a un destino"
 * separada del matching (el Matcher resuelve Y persiste en el mismo paso, dentro de
 * `legacy.pedidos`), `mapeados` y `cargados` quedan ambos iguales al conteo de `matched`.
 * `excluidos` = `orphan` (pedidos que no terminaron con un `legacy_cliente_id` resuelto).
 *
 * El link a la entrega (`icg_seriealbaran`/`icg_numeroalbaran`) queda crudo y sin resolver — fuera
 * de alcance de este batch, se resuelve cuando se implemente Albaranes (otro ticket).
 *
 * ITEMS PAGINADOS DESDE 2026-08-21 — fix preventivo, mismo patrón que
 * `App\Console\Commands\LegacySyncCotizacionesCommand` tras el OOM real de SCRUM-757 (ver su
 * docblock para el detalle completo del hallazgo). Este comando ya no pide TODOS los items al
 * Extractor — usa `PedidoExtractorContract::extractItemsPage()` a través de
 * `PedidoStagingLoader::loadPaginated()`.
 */
final class LegacySyncPedidosCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-pedidos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-758 — extrae PEDVENTACAB/PEDVENTALIN de ICG, stagea en legacy.pedidos/pedido_items y resuelve legacy_cliente_id por lookup contra legacy.clientes';

    public function __construct(
        private readonly PedidoExtractorContract $extractor,
        private readonly PedidoStagingLoader $stagingLoader,
        private readonly PedidoMatcher $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncCotizacionesCommand/LegacySyncClientesCommand (§13.3): el
        // memory_limit default de PHP CLI (128M) no alcanza ni para las 43,778 cabeceras de
        // PEDVENTACAB solas. ini_set() aplica directo porque este comando ES el proceso PHP
        // top-level. Items (PEDVENTALIN, 173,764 filas) ya NO se traen enteros de una — ver
        // docblock de la clase, "ITEMS PAGINADOS".
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
            $pedidos = $this->extractor->extractPedidos();
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

        // Mismo patrón que LegacySyncCotizacionesCommand (hallazgo Pre-QA SCRUM-750): un segundo
        // try/catch alrededor de staging+matching — sin esto, cualquier falla acá dejaba la
        // corrida sin rastro en legacy.sync_runs.
        try {
            return $this->stageAndMatch($pedidos, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear pedidos: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($pedidos), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    /** @param list<PedidoRow> $pedidos */
    private function stageAndMatch(array $pedidos, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $itemsExtracted = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingCounts = $this->stagingLoader->loadPaginated($pedidos, $itemPageFetcher);
        $matchResult   = $this->matcher->match($stagingCounts['pedido_ids']);

        $this->renderReport(count($pedidos), $itemsExtracted, $stagingCounts, $matchResult);

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
            extraidos: count($pedidos),
            staged: $stagingCounts['pedidos'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * @param array{pedidos: int, items: int, pedido_ids: list<int>} $stagingCounts
     * @param array{matched: int, orphan: int, errores: int}         $matchResult
     */
    private function renderReport(int $pedidosExtraidos, int $itemsExtraidos, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-pedidos — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (PEDVENTACAB)', $pedidosExtraidos],
            ['Extraídos (PEDVENTALIN)', $itemsExtraidos],
            ['Staged — pedidos', $stagingCounts['pedidos']],
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
            'entidad'     => 'pedidos',
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
