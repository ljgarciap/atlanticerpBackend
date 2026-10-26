<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Contracts\IcgPaginatedExtractorContract;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\AlmacenStagingLoader;
use App\Modules\Legacy\Support\AlmacenEntidades;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). Staging en crudo de almacenes, stock, costos, traspasos e inventarios de
 * ICG en `legacy.*` (diseño `docs/architecture/icg-staging-almacenes-diseno.md`). Solo lee de ICG
 * (SELECT); sin Matcher ni Loader-B; no toca `catalog_products` ni `bodega.*`.
 *
 * `--entidad=` acota a una entidad (almacenes | stock_almacen | costo_almacen | traspasos | inventarios);
 * sin la opción corre las cinco en ese orden (almacenes primero, para que la verificación vea los huérfanos).
 *
 * `--dry-run` = ensayo REAL: extrae y hace el upsert dentro de una transacción que se revierte al final
 * (valida contra las restricciones de Postgres sin dejar datos) y NO registra la corrida en
 * `legacy.sync_runs`. Cada entidad confirma página por página (ver `AlmacenStagingLoader`): si la
 * conexión a ICG cae a mitad de camino la corrida queda `partial` y la siguiente continúa idempotente.
 *
 * Sin scheduler: la programación diaria es F3.
 */
final class LegacySyncAlmacenesCommand extends Command
{
    /** Filas por página de lectura de ICG (constante técnica de memoria, no valor de negocio). */
    public const PAGE_SIZE = 5000;

    /** @var string */
    protected $signature = 'legacy:sync-almacenes
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--entidad= : Una sola entidad (almacenes, stock_almacen, costo_almacen, traspasos, inventarios); vacío = todas}
        {--dry-run : Extrae y hace upsert dentro de una transacción que se revierte; no registra la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-1136 — extrae almacenes, stock, costos, traspasos e inventarios de ICG y los stagea en legacy.* (solo lectura sobre ICG)';

    public function __construct(private readonly AlmacenStagingLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Una página a la vez (~5.000 filas), pero el default de PHP CLI (128M) queda corto para el lote de filas tipadas.
        MemoryLimit::atLeast('512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');
        $entidadOpt = trim((string) $this->option('entidad'));

        if ($entidadOpt !== '' && ! AlmacenEntidades::existe($entidadOpt)) {
            $this->error("Entidad '{$entidadOpt}' desconocida. Válidas: " . implode(', ', AlmacenEntidades::todas()) . '.');

            return self::FAILURE;
        }
        $entidades = $entidadOpt === '' ? AlmacenEntidades::todas() : [$entidadOpt];

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            $exit = self::SUCCESS;
            foreach ($entidades as $entidad) {
                if ($this->runEntidad($entidad, $isDryRun) !== self::SUCCESS) {
                    $exit = self::FAILURE;
                }
            }

            return $exit;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runEntidad(string $entidad, bool $isDryRun): int
    {
        $startedAt = now();
        $start     = microtime(true);

        /** @var IcgPaginatedExtractorContract $extractor */
        $extractor = app(AlmacenEntidades::extractor($entidad));
        $fetcher   = static fn (int $offset, int $limit): array => $extractor->extractPage($offset, $limit);

        $extraidos = 0;
        $cargados  = 0;
        $onPage    = static function (int $e, int $c) use (&$extraidos, &$cargados): void {
            $extraidos = $e;
            $cargados  = $c;
        };

        $error = null;
        try {
            if ($isDryRun) {
                try {
                    DB::transaction(function () use ($entidad, $fetcher, $onPage): void {
                        $this->loader->loadPaginated($entidad, $fetcher, self::PAGE_SIZE, $onPage);

                        throw new DryRunAbort();
                    });
                } catch (DryRunAbort) {
                    // rollback intencional del ensayo
                }
            } else {
                $this->loader->loadPaginated($entidad, $fetcher, self::PAGE_SIZE, $onPage);
            }
        } catch (Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            $this->error("Error al extraer/stagear {$entidad}: {$error->getMessage()}");
        }

        $this->info("legacy:sync-almacenes [{$entidad}]" . ($isDryRun ? ' (dry-run, revertido)' : ''));
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos', $extraidos],
            ['Staged (únicos)', $cargados],
        ]);

        if ($isDryRun) {
            return $error === null ? self::SUCCESS : self::FAILURE;
        }

        $this->recordRun($entidad, $startedAt, $start, $extraidos, $cargados, $error);

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    private function recordRun(string $entidad, Carbon $startedAt, float $start, int $extraidos, int $cargados, ?Throwable $error): void
    {
        $status = match (true) {
            $error === null => SyncRun::STATUS_SUCCESS,
            // Páginas ya confirmadas: la siguiente corrida (idempotente) completa el resto.
            $cargados > 0   => SyncRun::STATUS_PARTIAL,
            default         => SyncRun::STATUS_FAILED,
        };

        SyncRun::query()->create([
            'entidad'     => $entidad,
            'extraidos'   => $extraidos,
            'staged'      => $cargados,
            'mapeados'    => $cargados,
            'cargados'    => $cargados,
            'excluidos'   => 0,
            'errores'     => $error === null ? 0 : 1,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
