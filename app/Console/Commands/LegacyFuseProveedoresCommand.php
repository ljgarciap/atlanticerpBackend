<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ProveedorFusionLoader;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Épica SCRUM-749 (Migración ICG→AtlanticERP) — "Loader-B" de Proveedores (ver docblock de
 * `App\Modules\Legacy\Services\ProveedorFusionLoader` para el diseño completo, incluido el match
 * por nombre contra proveedores ya cargados a mano). No toca ICG, opera enteramente sobre
 * `legacy.proveedores` y `compras.providers`.
 *
 * MODO `--dry-run` (corregido 2026-08-26, mismo fix y mismo motivo que `LegacyFuseClientesCommand`
 * — ver su docblock y `memory/project_scrum755_758_tarea6_procedimiento_real.md`): rollback real
 * vía `DB::transaction()` + `DryRunAbort`, no escribe nada.
 */
final class LegacyFuseProveedoresCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:fuse-proveedores
        {--tenant=illuminations : Slug del tenant a procesar}
        {--dry-run : Preview real (rollback) — no escribe nada, no registra en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-749 — matchea por nombre o crea legacy.proveedores recurrentes en compras.providers';

    public function __construct(private readonly ProveedorFusionLoader $loader)
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
            $this->error("Error al fusionar proveedores: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, candidatos: 0, matched: 0, promovidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        $this->info('legacy:fuse-proveedores — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Candidatos (sin linkear, con ≥1 artículo)', $result['candidatos']],
            ['Matched (nombre ya existía en compras.providers)', $result['matched']],
            ['Promovidos (provider nuevo creado)', $result['promovidos']],
            ['Errores', $result['errores']],
        ]);

        if ($isDryRun) {
            $this->newLine();
            $this->comment('Modo --dry-run: rollback real — NO se escribió nada, NO se registró la corrida en legacy.sync_runs.');

            return self::SUCCESS;
        }

        $status = $result['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;
        $this->recordRun(
            $startedAt, $start,
            candidatos: $result['candidatos'],
            matched: $result['matched'],
            promovidos: $result['promovidos'],
            errores: $result['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * @return array{candidatos: int, matched: int, promovidos: int, errores: int}
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

        /** @var array{candidatos: int, matched: int, promovidos: int, errores: int} $result */
        return $result;
    }

    private function recordRun(Carbon $startedAt, float $start, int $candidatos, int $matched, int $promovidos, int $errores, string $status): void
    {
        SyncRun::query()->create([
            'entidad'     => 'proveedores_fusion',
            'extraidos'   => $candidatos,
            'staged'      => $candidatos,
            'mapeados'    => $matched + $promovidos,
            'cargados'    => $matched + $promovidos,
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
