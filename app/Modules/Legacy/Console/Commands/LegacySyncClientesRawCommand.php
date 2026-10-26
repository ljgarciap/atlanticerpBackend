<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Contracts\IcgPaginatedExtractorContract;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteRawStagingLoader;
use App\Modules\Legacy\Support\ClienteRawEntidades;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * SCRUM-1137 F1. Copia cruda y completa de CLIENTES y CONTACTOSCLIENTES de ICG en `legacy.clientes_raw` y
 * `legacy.contactos_cliente_raw` (diseño `docs/architecture/icg-clientes-staging-completo-diseno.md`). Solo
 * lee de ICG (SELECT); no toca `legacy.clientes`, `sub_clients` ni ninguna tabla viva. Las columnas de
 * tarjeta/credenciales/foto no se copian (`ClienteRawColumnas`).
 *
 * Corre las dos tablas en orden (clientes, luego contactos) y registra UNA corrida en `legacy.sync_runs`
 * con entidad `clientes_raw` (extraidos/cargados = suma de ambas). Cada tabla confirma página por página:
 * si la conexión a ICG cae a mitad de camino la corrida queda `partial` y la siguiente continúa idempotente.
 *
 * `--dry-run` = ensayo REAL: extrae y hace el upsert dentro de una transacción que se revierte al final y NO
 * registra la corrida.
 *
 * SEGURIDAD (REVIEW-icg-clientes-staging-f1.md, condición 2): la salida solo muestra conteos. Los errores de
 * base de datos se reducen a su SQLSTATE: el mensaje de un `QueryException` trae el SQL con los valores de la
 * fila (datos personales y bancarios) y no debe llegar a la consola ni a un log.
 *
 * Sin scheduler: se evalúa sumarlo a `legacy:sync-incremental-all` después.
 */
final class LegacySyncClientesRawCommand extends Command
{
    /** Filas por página de lectura de ICG (constante técnica de memoria, no valor de negocio). */
    public const PAGE_SIZE = 2000;

    /** @var string */
    protected $signature = 'legacy:sync-clientes-raw
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Extrae y hace upsert dentro de una transacción que se revierte; no registra la corrida en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-1137 — copia cruda de CLIENTES y CONTACTOSCLIENTES de ICG en legacy.clientes_raw / legacy.contactos_cliente_raw (solo lectura sobre ICG)';

    public function __construct(private readonly ClienteRawStagingLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Una página a la vez, pero ~113 columnas por fila: el default de PHP CLI (128M) queda corto.
        MemoryLimit::atLeast('512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->ejecutar($isDryRun);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(bool $isDryRun): int
    {
        $startedAt = now();
        $start     = microtime(true);

        $extraidosTotal = 0;
        $cargadosTotal  = 0;
        $filas          = [];
        $error          = null;

        foreach (ClienteRawEntidades::todas() as $entidad) {
            /** @var IcgPaginatedExtractorContract $extractor */
            $extractor = app(ClienteRawEntidades::extractor($entidad));
            $fetcher   = static fn (int $offset, int $limit): array => $extractor->extractPage($offset, $limit);

            $extraidos = 0;
            $cargados  = 0;
            $resultado = null;
            $onPage    = static function (int $e, int $c) use (&$extraidos, &$cargados): void {
                $extraidos = $e;
                $cargados  = $c;
            };

            try {
                if ($isDryRun) {
                    try {
                        DB::transaction(function () use ($entidad, $fetcher, $onPage, &$resultado): void {
                            $resultado = $this->loader->loadPaginated($entidad, $fetcher, self::PAGE_SIZE, $onPage);

                            throw new DryRunAbort();
                        });
                    } catch (DryRunAbort) {
                        // rollback intencional del ensayo
                    }
                } else {
                    $resultado = $this->loader->loadPaginated($entidad, $fetcher, self::PAGE_SIZE, $onPage);
                }
            } catch (Throwable $e) {
                $error = $e;
            }

            $extraidosTotal += $extraidos;
            $cargadosTotal  += $cargados;
            $filas[]         = [$entidad, $extraidos, $cargados, $resultado['cambiados'] ?? '-'];

            if ($error !== null) {
                $this->error("Error al extraer/stagear {$entidad}: " . self::safeMessage($error));
                // Ubica la falla sin imprimir valores: las páginas anteriores quedaron confirmadas.
                $this->error(sprintf(
                    'Falló la página que empieza en la fila %d (de a %d, orden por llave) de %s; las %d filas anteriores quedaron confirmadas.',
                    $extraidos,
                    self::PAGE_SIZE,
                    $entidad,
                    $extraidos,
                ));

                break; // la segunda tabla no corre si la primera falló: la corrida queda partial/failed
            }
        }

        $this->info('legacy:sync-clientes-raw' . ($isDryRun ? ' (dry-run, revertido)' : ''));
        $this->table(['Entidad', 'Extraídos', 'Staged (únicos)', 'Insertados o modificados'], $filas);

        if (! $isDryRun) {
            $this->recordRun($startedAt, $start, $extraidosTotal, $cargadosTotal, $error);
        }

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Mensaje apto para consola/logs: un error de base de datos se reduce a su SQLSTATE (su mensaje trae el
     * SQL con los valores de la fila). El resto (RuntimeException propias, p. ej. llave nula) ya es seguro.
     */
    public static function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException || $e instanceof PDOException || $e->getPrevious() instanceof PDOException) {
            $pdo  = $e instanceof PDOException ? $e : $e->getPrevious();
            $code = $pdo instanceof PDOException && isset($pdo->errorInfo[0]) ? $pdo->errorInfo[0] : $e->getCode();

            return 'error de base de datos (SQLSTATE ' . (string) $code . '); el detalle se omite a propósito para no imprimir valores.';
        }

        return $e->getMessage();
    }

    private function recordRun(Carbon $startedAt, float $start, int $extraidos, int $cargados, ?Throwable $error): void
    {
        $status = match (true) {
            $error === null => SyncRun::STATUS_SUCCESS,
            // Páginas ya confirmadas: la siguiente corrida (idempotente) completa el resto.
            $cargados > 0   => SyncRun::STATUS_PARTIAL,
            default         => SyncRun::STATUS_FAILED,
        };

        SyncRun::query()->create([
            'entidad'     => ClienteRawEntidades::SYNC_RUN_ENTIDAD,
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
