<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteFusionLoader;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Épica SCRUM-749 (Migración ICG→AtlanticERP) — "Loader-B" de Clientes (ver docblock de
 * `App\Modules\Legacy\Services\ClienteFusionLoader` para el diseño completo). A diferencia de los
 * comandos `legacy:sync-*`, este NO toca ICG — opera enteramente sobre `legacy.clientes` (ya
 * staged) y `ventas_diseno.{master_clients,sub_clients}`, así que no necesita `ini_set(memory_limit)`
 * ni paginación: el volumen (miles, no cientos de miles) y el trabajo (Postgres↔Postgres, sin
 * extracción de SQL Server) están muy lejos del gotcha de memoria de Cotizaciones/Pedidos.
 *
 * MODO `--dry-run` (corregido 2026-08-26 — ver `DryRunAbort` y hallazgo real en
 * `memory/project_scrum755_758_tarea6_procedimiento_real.md`): antes corría igual que sin la
 * bandera (creaba `master_clients`/`sub_clients` reales) y SOLO se saltaba el registro en
 * `legacy.sync_runs` — mismo criterio que `legacy:sync-*`, pero peligroso acá porque el criterio
 * de candidato (`sub_client_id IS NULL`) hace que una corrida `--dry-run` "consuma" los candidatos
 * en silencio: una corrida real posterior los encuentra todos ya vinculados y se registra con
 * 0/0/0/0, sin ningún rastro de que la fusión sí ocurrió. Ahora `--dry-run` envuelve
 * `loader->fuse()` en una `DB::transaction()` externa y fuerza rollback (lanzando `DryRunAbort`,
 * capturada inmediatamente después) — no escribe nada, punto. Reporta los mismos conteos que una
 * corrida real hubiera producido, para poder previsualizar antes de prod.
 */
final class LegacyFuseClientesCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:fuse-clientes
        {--tenant=illuminations : Slug del tenant a procesar}
        {--dry-run : Preview real (rollback) — no escribe nada, no registra en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-749 — promueve legacy.clientes recurrentes sin match real a sub_clients/master_clients nuevos';

    public function __construct(private readonly ClienteFusionLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
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
            return $this->runFusion($isDryRun, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runFusion(bool $isDryRun, Carbon $startedAt, float $start): int
    {
        try {
            $result = $isDryRun ? $this->fuseWithRollback() : $this->loader->fuse();
        } catch (Throwable $e) {
            $this->error("Error al fusionar clientes: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, candidatos: 0, promovidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        $this->info('legacy:fuse-clientes — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Candidatos (sin match, con transacción real)', $result['candidatos']],
            ['Promovidos (sub_client nuevo creado)', $result['promovidos']],
            ['Errores', $result['errores']],
        ]);

        if ($isDryRun) {
            $this->newLine();
            $this->comment('Modo --dry-run: rollback real — NO se escribió nada, NO se registró la corrida en legacy.sync_runs.');

            return self::SUCCESS;
        }

        $status = $result['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;
        $this->recordRun($startedAt, $start, candidatos: $result['candidatos'], promovidos: $result['promovidos'], errores: $result['errores'], status: $status);

        return self::SUCCESS;
    }

    /**
     * Corre `loader->fuse()` dentro de una `DB::transaction()` externa y fuerza rollback al final
     * — `promoteOne()` ya abre su propia transacción por cliente (se anida vía savepoints sobre la
     * misma conexión), así que envolverla acá revierte también esas escrituras internas. Ver
     * docblock de la clase para el porqué.
     *
     * @return array{candidatos: int, promovidos: int, errores: int}
     */
    private function fuseWithRollback(): array
    {
        $result = null;

        try {
            DB::transaction(function () use (&$result): void {
                $result = $this->loader->fuse();

                throw new DryRunAbort();
            });
        } catch (DryRunAbort) {
            // esperado — fuerza el rollback, $result ya quedó capturado por referencia.
        }

        /** @var array{candidatos: int, promovidos: int, errores: int} $result */
        return $result;
    }

    private function recordRun(Carbon $startedAt, float $start, int $candidatos, int $promovidos, int $errores, string $status): void
    {
        SyncRun::query()->create([
            'entidad'     => 'clientes_fusion',
            'extraidos'   => $candidatos,
            'staged'      => $candidatos,
            'mapeados'    => $promovidos,
            'cargados'    => $promovidos,
            'excluidos'   => 0,
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
