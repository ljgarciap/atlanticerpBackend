<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Jobs\SyncProductoFotoJob;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ProductoFotoConverter;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-751 (seguimiento de SCRUM-750, épica SCRUM-749 — Migración ICG→AtlanticERP).
 * Illumination/migracion-icg-diseno.md §15.4. Arma el worklist (`legacy.productos` con
 * `catalog_product_id` seteado Y `catalog_products.photo_url` todavía null) y despacha un
 * `SyncProductoFotoJob` por fila.
 *
 * MODO `--dry-run`: extrae y decodifica CADA fila del worklist para medir (tamaños reales
 * post-resize, duplicados reales por hash) pero NUNCA sube a S3 ni escribe en Postgres — corre
 * síncrono, en el propio proceso del comando (no despacha Jobs), igual que
 * `LegacySyncProductosCommand` de SCRUM-750. No registra en `legacy.sync_runs` por el mismo
 * motivo que aquel: la tabla es "una fila por corrida REAL".
 *
 * `--limit=N` corta el worklist a las primeras N filas (por `id` ascendente) — pensado para una
 * primera corrida real acotada de humo (§15.5 paso 2: `--limit=50`) antes de soltar las ~8000
 * fotos únicas reales sin límite.
 *
 * DECISIÓN DE DISEÑO — a diferencia de `legacy:sync-productos` (SCRUM-750, síncrono de punta a
 * punta, así que su fila de `legacy.sync_runs` refleja el resultado FINAL real de la corrida),
 * este comando en modo real solo DESPACHA Jobs a Horizon — el procesamiento real (extracción +
 * conversión + upload + escritura) ocurre después, de forma asíncrona, potencialmente a lo largo
 * de horas para ~8000 fotos. La fila de `legacy.sync_runs` que registra este comando refleja
 * ÚNICAMENTE el dispatch (cuántas filas calificaron para el worklist y fueron encoladas), NO el
 * resultado final de cada Job — `cargados`/`excluidos`/`errores` quedan en 0 a propósito. La
 * verificación real de "cuántas fotos quedaron cargadas" es SIEMPRE por consulta directa a
 * Postgres tras dejar correr la cola (mismo criterio que §13.4 del runbook: "verificar siempre por
 * consulta directa a la base, no solo por el output del comando") — documentado acá explícitamente
 * para que quede claro en Senior Review, no es un bug ni una copia incompleta del patrón de
 * SCRUM-750.
 */
final class LegacySyncProductosFotosCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-productos-fotos
        {--tenant=illuminations : Slug del tenant a sincronizar}
        {--dry-run : Extrae+decodifica cada fila del worklist para medir, sin subir a S3 ni escribir en Postgres, sin despachar Jobs}
        {--limit= : Corta el worklist a las primeras N filas (por id ascendente)}';

    /** @var string */
    protected $description = 'SCRUM-751 — arma el worklist de legacy.productos sin foto y despacha un SyncProductoFotoJob por fila (Horizon)';

    public function handle(): int
    {
        // Mismo criterio que LegacySyncProductosCommand (SCRUM-750, §13.3): el comando ES el
        // proceso PHP top-level, así que ini_set() aplica directo sin necesitar el flag -d.
        ini_set('memory_limit', '512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');
        $limitOpt   = $this->option('limit');
        $limit      = $limitOpt !== null ? (int) $limitOpt : null;

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $startedAt = now();
        $start     = microtime(true);

        $tenant->makeCurrent();
        try {
            return $this->runPipeline($isDryRun, $limit, $tenantSlug, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runPipeline(bool $isDryRun, ?int $limit, string $tenantSlug, Carbon $startedAt, float $start): int
    {
        // Mismo patrón de doble try/catch que LegacySyncProductosCommand (§13.3 / hallazgo Pre-QA
        // SCRUM-750): cualquier falla, en cualquier etapa, deja rastro en legacy.sync_runs (salvo
        // en --dry-run, que nunca registra corridas).
        try {
            $worklist = $this->buildWorklist($limit);
        } catch (Throwable $e) {
            $this->error("Error al armar el worklist: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: 0, encolados: 0, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        try {
            if ($isDryRun) {
                return $this->runDryRun($worklist);
            }

            return $this->dispatchJobs($worklist, $tenantSlug, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al despachar los Jobs: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($worklist), encolados: 0, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    /** @return Collection<int, Producto> */
    private function buildWorklist(?int $limit): Collection
    {
        $pendingCatalogProductIds = CatalogProduct::query()->whereNull('photo_url')->pluck('id');

        $query = Producto::query()
            ->whereNotNull('catalog_product_id')
            ->whereIn('catalog_product_id', $pendingCatalogProductIds)
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /** @param Collection<int, Producto> $worklist */
    private function runDryRun(Collection $worklist): int
    {
        $extractor = app(ArticuloFotoExtractorContract::class);
        $converter = app(ProductoFotoConverter::class);

        $conSuFoto      = 0;
        $sinFoto        = 0;
        $duplicados     = 0;
        $decodeFallidos = 0;
        $bytesOriginales = 0;
        $bytesJpeg       = 0;

        /** @var array<string, true> $seenHashes */
        $seenHashes = [];

        foreach ($worklist as $producto) {
            $bmp = $extractor->extract($producto->icg_codarticulo);

            if ($bmp === null) {
                $sinFoto++;

                continue;
            }

            $conSuFoto++;
            $bytesOriginales += strlen($bmp);

            $hash = hash('sha256', $bmp);

            if (isset($seenHashes[$hash])) {
                $duplicados++;

                continue;
            }
            $seenHashes[$hash] = true;

            $result = $converter->convert($bmp);

            if (! $result->success) {
                $decodeFallidos++;

                continue;
            }

            $bytesJpeg += strlen((string) $result->jpegBytes);
        }

        $this->info('legacy:sync-productos-fotos --dry-run — reporte');
        $this->table(['Métrica', 'Valor'], [
            ['Candidatos en el worklist', count($worklist)],
            ['Con FOTO en ICG', $conSuFoto],
            ['Sin FOTO en ICG', $sinFoto],
            ['Duplicados por hash (se reusarían, no se subirían)', $duplicados],
            ['Fallos de decodificación BMP (se omitirían)', $decodeFallidos],
            ['Bytes BMP originales (únicos a subir)', $bytesOriginales],
            ['Bytes JPEG resultantes (únicos a subir)', $bytesJpeg],
        ]);

        $this->newLine();
        $this->comment('Modo --dry-run: nada se subió a S3 ni se escribió en Postgres, ningún Job fue despachado.');

        return self::SUCCESS;
    }

    /** @param Collection<int, Producto> $worklist */
    private function dispatchJobs(Collection $worklist, string $tenantSlug, Carbon $startedAt, float $start): int
    {
        foreach ($worklist as $producto) {
            SyncProductoFotoJob::dispatch($producto->id, $tenantSlug);
        }

        $this->info(count($worklist) . " Job(s) despachados a Horizon ('legacy:sync-productos-fotos').");
        $this->comment('El resultado real (fotos cargadas/excluidas/fallidas) se ve solo tras dejar correr la cola — verificar por consulta directa a Postgres, ver docblock de la clase.');

        $this->recordRun($startedAt, $start, extraidos: count($worklist), encolados: count($worklist), status: SyncRun::STATUS_SUCCESS);

        return self::SUCCESS;
    }

    private function recordRun(Carbon $startedAt, float $start, int $extraidos, int $encolados, string $status): void
    {
        SyncRun::query()->create([
            'entidad'     => 'productos_fotos',
            'extraidos'   => $extraidos,
            'staged'      => $encolados, // reusado a propósito para "Jobs encolados" — este pipeline no tiene staging propio
            'mapeados'    => 0,
            'cargados'    => 0, // resultado real es asíncrono, ver docblock de la clase
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
