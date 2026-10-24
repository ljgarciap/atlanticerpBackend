<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Contracts\MovimientoExtractorContract;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\MovimientoStagingLoader;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1136 F2 (épica SCRUM-749). Carga única y reanudable de MOVIMENTS de ICG (1.384.677 filas) en
 * `legacy.movimientos` (diseño `docs/architecture/icg-staging-almacenes-diseno.md`, secciones 3 y 11). Solo lee
 * de ICG (SELECT); no toca `catalog_products` ni `bodega.*`.
 *
 * Pagina por llave (`ID > último icg_id cargado`, páginas de 5.000), una transacción por página y una sola
 * página en memoria. Reanuda sola desde `MAX(icg_id)`: tras una caída (`partial`) basta volver a correrlo.
 * `--max-paginas=N` limita la corrida a N páginas (carga por tandas).
 *
 * `--dry-run` = ensayo REAL: extrae y hace el upsert dentro de una transacción que se revierte al final y NO
 * registra la corrida en `legacy.sync_runs`. No se pueden cargar 1,38 M de filas en una sola transacción, así que
 * el ensayo se limita a `--max-paginas` (por defecto 3 páginas = 15.000 filas).
 *
 * Sin scheduler: ICG está congelado, no hay lectura incremental futura (la F3 del diseño se abandonó).
 */
final class LegacySyncMovimientosCommand extends Command
{
    /** Filas por página de lectura de ICG (constante técnica de memoria, no valor de negocio). */
    public const PAGE_SIZE = 5000;

    /** Páginas del ensayo `--dry-run` cuando no se pasa `--max-paginas` (constante técnica). */
    public const DRY_RUN_MAX_PAGINAS = 3;

    /** @var string */
    protected $signature = 'legacy:sync-movimientos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Ensayo: extrae y hace upsert en una transacción que se revierte, limitado a --max-paginas (3 por defecto); no registra la corrida}
        {--max-paginas= : Máximo de páginas de 5.000 filas a cargar en esta corrida (sin valor = hasta agotar ICG; en dry-run, 3)}';

    /** @var string */
    protected $description = 'SCRUM-1136 F2 — carga única reanudable de MOVIMENTS de ICG en legacy.movimientos (solo lectura sobre ICG)';

    public function __construct(private readonly MovimientoStagingLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Una página a la vez (~5.000 filas x 30 columnas), pero el default de PHP CLI (128M) queda corto.
        MemoryLimit::atLeast('512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');

        $maxOpt = $this->option('max-paginas');
        if ($maxOpt !== null && $maxOpt !== '' && (! ctype_digit((string) $maxOpt) || (int) $maxOpt < 1)) {
            $this->error('--max-paginas debe ser un entero >= 1.');

            return self::FAILURE;
        }
        $maxPaginas = ($maxOpt === null || $maxOpt === '') ? null : (int) $maxOpt;
        if ($isDryRun) {
            $maxPaginas ??= self::DRY_RUN_MAX_PAGINAS;
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->cargar($isDryRun, $maxPaginas);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function cargar(bool $isDryRun, ?int $maxPaginas): int
    {
        $startedAt = now();
        $start     = microtime(true);

        /** @var MovimientoExtractorContract $extractor */
        $extractor = app(MovimientoExtractorContract::class);
        $fetcher   = static fn (int $afterId, int $limit): array => $extractor->extractAfter($afterId, $limit);

        $extraidos = 0;
        $cargados  = 0;
        $onPage    = static function (int $e, int $c) use (&$extraidos, &$cargados): void {
            $extraidos = $e;
            $cargados  = $c;
        };

        $resultado = null;
        $error     = null;
        try {
            if ($isDryRun) {
                try {
                    DB::transaction(function () use ($fetcher, $maxPaginas, $onPage, &$resultado): void {
                        $resultado = $this->loader->load($fetcher, self::PAGE_SIZE, $maxPaginas, $onPage);

                        throw new DryRunAbort();
                    });
                } catch (DryRunAbort) {
                    // rollback intencional del ensayo
                }
            } else {
                $resultado = $this->loader->load($fetcher, self::PAGE_SIZE, $maxPaginas, $onPage);
            }
        } catch (Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            $this->error("Error al extraer/stagear movimientos: {$error->getMessage()}");
        }

        $this->info('legacy:sync-movimientos' . ($isDryRun ? ' (dry-run, revertido)' : ''));
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos', $extraidos],
            ['Staged (únicos)', $cargados],
        ]);
        if ($resultado !== null) {
            $this->line("Reanudó desde icg_id > {$resultado['desde_id']}; último icg_id de la corrida: {$resultado['ultimo_id']}.");
            if (! $resultado['completo']) {
                $this->warn('Corrida acotada por --max-paginas: ICG todavía tiene filas; volver a correr para continuar.');
            }
        }

        if ($isDryRun) {
            return $error === null ? self::SUCCESS : self::FAILURE;
        }

        $this->recordRun($startedAt, $start, $extraidos, $cargados, $error);

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    private function recordRun(Carbon $startedAt, float $start, int $extraidos, int $cargados, ?Throwable $error): void
    {
        $status = match (true) {
            $error === null => SyncRun::STATUS_SUCCESS,
            // Páginas ya confirmadas: la siguiente corrida reanuda desde MAX(icg_id).
            $cargados > 0   => SyncRun::STATUS_PARTIAL,
            default         => SyncRun::STATUS_FAILED,
        };

        SyncRun::query()->create([
            'entidad'     => 'movimientos',
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
