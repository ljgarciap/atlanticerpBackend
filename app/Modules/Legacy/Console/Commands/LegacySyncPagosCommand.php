<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\PagoStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-761 (épica SCRUM-749 — Migración ICG→AtlanticERP). Pipeline completo Extractor→Loader-A
 * (staging) para el batch de Pagos (Illumination/migracion-icg-diseno.md §5) — mismo patrón que
 * `LegacySyncProveedoresCommand` (SCRUM-756): sin Matcher (TESORERIA no referencia cliente), sin
 * Loader-B. El pipeline termina en `legacy.pagos`.
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) — escribe exclusivamente dentro de
 * `legacy.*`, upsert idempotente sobre una tabla de solo-lectura de referencia, así que correrlo
 * en modo rehearsal es seguro. Lo único que NUNCA pasa en dry-run es el registro de la corrida en
 * `legacy.sync_runs`.
 *
 * DECISIÓN DE DISEÑO — mapeo de campos de `legacy.sync_runs` (mismo criterio que
 * `LegacySyncProveedoresCommand`): como Pagos no tiene ni mapeo/matching ni carga a un destino
 * distinto de su propio staging, `mapeados`/`cargados` quedan ambos iguales al conteo de `staged`,
 * y `excluidos` siempre es 0 (sin criterio de exclusión en este batch).
 *
 * PAGINADO DESDE 2026-08-27 — la primera corrida real contra `dev.atlanticerp.ai` reventó por OOM del
 * kernel (2 kills confirmados por `dmesg -T`, mismo síntoma que Cotizaciones/Albaranes pese a ser
 * tabla única): TESORERIA (145,250 filas) extraída entera de una sola vez no entraba en la memoria
 * real disponible del host. Este comando ya no pide TODAS las filas al Extractor — usa
 * `PagoExtractorContract::extractPage()` a través de `PagoStagingLoader::loadPaginated()`, que
 * pagina la extracción y el staging juntos (nunca más de una página en memoria a la vez). A
 * diferencia de Albaranes/Cotizaciones no hay una cabecera separada de menor volumen — acá TODA la
 * extracción pasa por el loop paginado.
 */
final class LegacySyncPagosCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-pagos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A) y reporta conteos, sin registrar la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-761 — extrae TESORERIA de ICG y stagea en legacy.pagos (catálogo de referencia standalone, sin Matcher ni Loader-B)';

    public function __construct(
        private readonly PagoExtractorContract $extractor,
        private readonly PagoStagingLoader $stagingLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Mismo gotcha que LegacySyncClientesCommand/LegacySyncCotizacionesCommand (§13.3): el
        // memory_limit default de PHP CLI (128M) no alcanza. TESORERIA (145,250 filas) ya NO se
        // trae entera de una — ver docblock de la clase, "PAGINADO DESDE 2026-08-27" — 512M sigue
        // siendo margen de sobra para una página a la vez, muy lejos del límite real del host que
        // causó el OOM real cuando SÍ se traía todo junto.
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
        // Cuenta lo realmente extraído de TESORERIA a medida que llegan las páginas — sin esto no
        // hay forma barata de mostrar "Extraídos" en el reporte, ya que el Extractor nunca entrega
        // el total de filas de una — ver docblock de la clase.
        $extracted       = 0;
        $pageFetcher = function (int $offset, int $limit) use (&$extracted): array {
            $page = $this->extractor->extractPage($offset, $limit);
            $extracted += count($page);

            return $page;
        };

        // Mismo patrón que LegacySyncProveedoresCommand (hallazgo Pre-QA SCRUM-750): try/catch
        // alrededor de la extracción+staging — sin esto, cualquier falla acá dejaba la corrida sin
        // rastro en legacy.sync_runs. A diferencia de las demás entidades no hay un primer
        // try/catch separado solo para la extracción — acá extracción y staging ocurren
        // entrelazados dentro del mismo loop paginado, no hay forma de separarlos en dos pasos.
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

        $this->renderReport($extracted, $stagingCounts);

        if ($isDryRun) {
            $this->newLine();
            $this->comment(
                'Modo --dry-run: staging (legacy.pagos) actualizado, pero NO se registró la '
                . 'corrida en legacy.sync_runs.'
            );

            return self::SUCCESS;
        }

        $this->recordRun(
            $startedAt, $start,
            extraidos: $extracted,
            staged: $stagingCounts['pagos'],
            mapeados: $stagingCounts['pagos'],
            cargados: $stagingCounts['pagos'],
            excluidos: 0,
            errores: 0,
            status: SyncRun::STATUS_SUCCESS,
        );

        return self::SUCCESS;
    }

    /** @param array{pagos: int} $stagingCounts */
    private function renderReport(int $extraidos, array $stagingCounts): void
    {
        $this->info('legacy:sync-pagos — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (TESORERIA)', $extraidos],
            ['Staged — pagos', $stagingCounts['pagos']],
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
            'entidad'     => 'pagos',
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
