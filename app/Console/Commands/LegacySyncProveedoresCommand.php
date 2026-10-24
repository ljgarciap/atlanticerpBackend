<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ProveedorStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-756 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging) para el batch de Proveedores (Illumination/migracion-icg-diseno.md §5) — el más
 * simple de los patrones existentes: sin Matcher (a diferencia de `LegacySyncClientesCommand`),
 * sin Loader-B (a diferencia de `LegacySyncProductosCommand`). El pipeline termina en
 * `legacy.proveedores` (ver runbook §12.1: "el resto de las entidades NO tiene Loader-B — quedan
 * exclusivamente en legacy.*").
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) — escribe exclusivamente dentro de
 * `legacy.*`, upsert idempotente sobre una tabla de solo-lectura de referencia, así que correrlo
 * en modo rehearsal es seguro (mismo criterio que `LegacySyncProductosCommand`/
 * `LegacySyncClientesCommand`). Lo único que NUNCA pasa en dry-run es el registro de la corrida en
 * `legacy.sync_runs` — mismo motivo que las otras dos entidades: esa tabla es "una fila por
 * corrida REAL" (ver docblock de la migración de `sync_runs`).
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs`: como Proveedores no tiene ni
 * mapeo/matching ni carga a un destino distinto de su propio staging, `mapeados` y `cargados`
 * quedan ambos iguales al conteo de `staged`, y `excluidos` siempre es 0 (no hay criterio de
 * exclusión en este batch, ver docblock de `ProveedorExtractor`).
 *
 * La corrida real contra ICG (gateway VPN / dev.atlanticerp.ai / test.atlanticerp.ai) es explícitamente FUERA
 * de este batch — este comando nunca se corrió contra SQL Server real todavía.
 */
final class LegacySyncProveedoresCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-proveedores
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A) y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-756 — extrae PROVEEDORES de ICG y stagea en legacy.proveedores (catálogo de referencia standalone, sin Matcher ni Loader-B)';

    public function __construct(
        private readonly ProveedorExtractorContract $extractor,
        private readonly ProveedorStagingLoader $stagingLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncProductosCommand/LegacySyncClientesCommand (SCRUM-750 Tarea
        // 6) — con solo 114 filas (§2 del diseño) el volumen real de Proveedores nunca se va a
        // acercar al memory_limit default de PHP CLI (128M), pero se replica igual por
        // consistencia con el resto del módulo.
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

        // Mismo patrón que LegacySyncProductosCommand/LegacySyncClientesCommand (hallazgo Pre-QA
        // SCRUM-750): un segundo try/catch alrededor del staging — sin esto, cualquier falla acá
        // dejaba la corrida sin rastro en legacy.sync_runs (vacío de monitoreo #5 del diseño, §11).
        try {
            return $this->stage($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear proveedores: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($extracted->proveedores), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    private function stage(ExtractedProveedores $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $stagingCounts = $this->stagingLoader->load($extracted);

        $this->renderReport($extracted, $stagingCounts);

        if ($isDryRun) {
            $this->newLine();
            $this->comment(
                'Modo --dry-run: staging (legacy.proveedores) actualizado, pero NO se registró '
                . 'la corrida en legacy.sync_runs.'
            );

            return self::SUCCESS;
        }

        $this->recordRun(
            $startedAt, $start,
            extraidos: count($extracted->proveedores),
            staged: $stagingCounts['proveedores'],
            mapeados: $stagingCounts['proveedores'],
            cargados: $stagingCounts['proveedores'],
            excluidos: 0,
            errores: 0,
            status: SyncRun::STATUS_SUCCESS,
        );

        return self::SUCCESS;
    }

    /** @param array{proveedores: int} $stagingCounts */
    private function renderReport(ExtractedProveedores $extracted, array $stagingCounts): void
    {
        $this->info('legacy:sync-proveedores — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (PROVEEDORES)', count($extracted->proveedores)],
            ['Staged — proveedores', $stagingCounts['proveedores']],
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
            'entidad'     => 'proveedores',
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
