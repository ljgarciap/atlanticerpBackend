<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\FacturaMatcher;
use App\Modules\Legacy\Services\FacturaStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-760 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging)→Matcher para el batch de Facturas (Illumination/migracion-icg-diseno.md §5). Igual que
 * `LegacySyncAlbaranesCommand` (SCRUM-759), esta entidad NO tiene Loader-B — el `FacturaMatcher`
 * persiste el resultado directamente en `legacy.facturas`. El Matcher acá es un LOOKUP simple
 * contra `legacy.clientes` ya poblada — no re-matchea por CIF/email. Sin etapa de líneas (ver
 * docblock de `FacturaStagingLoader` — FACTURASVENTA no tiene tabla de líneas documentada).
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Matcher — TODOS escriben exclusivamente
 * dentro de `legacy.*`, upserts idempotentes sobre tablas de solo-lectura de referencia, así que
 * correrlos en modo rehearsal es seguro. Lo único que NUNCA pasa en dry-run es el registro de la
 * corrida en `legacy.sync_runs`.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (mismo criterio que
 * `LegacySyncAlbaranesCommand`): `mapeados`/`cargados` quedan ambos iguales al conteo de `matched`.
 * `excluidos` = `orphan`.
 *
 * La corrida real contra ICG (gateway VPN / dev.atlanticerp.ai / test.atlanticerp.ai) es explícitamente FUERA
 * de este batch — este comando nunca se corrió contra SQL Server real todavía.
 */
final class LegacySyncFacturasCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-facturas
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Matcher y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-760 — extrae FACTURASVENTA de ICG, stagea en legacy.facturas y resuelve legacy_cliente_id por lookup contra legacy.clientes';

    public function __construct(
        private readonly FacturaExtractorContract $extractor,
        private readonly FacturaStagingLoader $stagingLoader,
        private readonly FacturaMatcher $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncAlbaranesCommand/LegacySyncPedidosCommand (§13.3): el
        // volumen real de FACTURASVENTA (60,215 filas §2) revienta el memory_limit default de PHP
        // CLI (128M) por buen margen.
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

        // Mismo patrón que LegacySyncAlbaranesCommand (hallazgo Pre-QA SCRUM-750): un segundo
        // try/catch alrededor de staging+matching — sin esto, cualquier falla acá dejaba la
        // corrida sin rastro en legacy.sync_runs.
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
        $stagingCounts = $this->stagingLoader->load($extracted);
        $matchResult   = $this->matcher->match($stagingCounts['factura_ids']);

        $this->renderReport($extracted, $stagingCounts, $matchResult);

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
            extraidos: count($extracted->facturas),
            staged: $stagingCounts['facturas'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * @param array{facturas: int, factura_ids: list<int>} $stagingCounts
     * @param array{matched: int, orphan: int, errores: int} $matchResult
     */
    private function renderReport(ExtractedFacturas $extracted, array $stagingCounts, array $matchResult): void
    {
        $this->info('legacy:sync-facturas — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (FACTURASVENTA)', count($extracted->facturas)],
            ['Staged — facturas', $stagingCounts['facturas']],
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
            'entidad'     => 'facturas',
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
