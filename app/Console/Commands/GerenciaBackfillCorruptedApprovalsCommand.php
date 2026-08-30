<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-161/162 (2026-08-26) — backfill de los registros que `GerenciaAprobacionController`
 * corrompió antes del rediseño: escribía literales de enum en vez de la constante real de cada
 * modelo (ej. `'aprobado'` en vez de `'aprobada'`), así que el registro nunca era reconocido como
 * resuelto por su propio módulo (`isAprobada()`/`isPending()` seguían viendo un estado
 * desconocido). Confirmado EN VIVO en `dev.atlanticerp.ai` durante Pre-QA v7 (2026-08-26): `general_counts`
 * id=5 (CNT-5) quedó con `estado='aprobado'`.
 *
 * Cubre los 4 tipos no-PO que `GerenciaAprobacionController` maneja (mismo bug estructural en los
 * 4, aunque solo `general_counts` id=5 esté confirmado con datos reales corruptos hoy) — no asume
 * que es el único registro afectado, cualquier fila que haya pasado por el código viejo puede
 * tener el mismo problema.
 *
 * Idempotente: solo actualiza filas cuyo `estado`/`status` sea EXACTAMENTE uno de los literales
 * inválidos conocidos — correrlo de nuevo tras corregir no encuentra nada que tocar (no-op).
 */
final class GerenciaBackfillCorruptedApprovalsCommand extends Command
{
    /** @var string */
    protected $signature = 'gerencia:backfill-corrupted-approvals
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que corregiría, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-161/162 — corrige registros de relocation/adjustment/general_count/zona_libre con literales de enum inválidos escritos por el GerenciaAprobacionController viejo';

    public function handle(): int
    {
        $tenantSlug = (string) $this->option('tenant');
        $dryRun     = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        $totalFixed = 0;

        $totalFixed += $this->fix(
            RelocationRequest::class,
            'estado',
            ['aprobado' => RelocationRequest::ESTADO_APROBADA, 'rechazado' => RelocationRequest::ESTADO_RECHAZADA],
            'REU',
            $dryRun,
        );

        $totalFixed += $this->fix(
            AdjustmentRequestLine::class,
            'estado',
            ['Aprobado' => AdjustmentRequestLine::ESTADO_APROBADA, 'Rechazado' => AdjustmentRequestLine::ESTADO_RECHAZADA],
            'AJS',
            $dryRun,
        );

        $totalFixed += $this->fix(
            GeneralCountRequest::class,
            'estado',
            ['aprobado' => GeneralCountRequest::ESTADO_APROBADA, 'rechazado' => GeneralCountRequest::ESTADO_RECHAZADA],
            'CNT',
            $dryRun,
        );

        $totalFixed += $this->fix(
            BodegaZonaLibreRequest::class,
            'status',
            ['aprobado' => BodegaZonaLibreRequest::STATUS_APROBADA, 'rechazado' => BodegaZonaLibreRequest::STATUS_RECHAZADA],
            'ZL',
            $dryRun,
        );

        if ($totalFixed === 0) {
            $this->info('Nada que corregir — ningún registro con literales inválidos conocidos.');
        } elseif ($dryRun) {
            $this->info("Dry-run: {$totalFixed} registro(s) tienen un literal inválido y se corregirían en una corrida real.");
        } else {
            $this->info("{$totalFixed} registro(s) corregidos.");
        }

        return self::SUCCESS;
    }

    /**
     * @param class-string $modelClass
     * @param array<string, string> $literalToReal literal inválido => valor real de la constante
     */
    private function fix(string $modelClass, string $column, array $literalToReal, string $prefix, bool $dryRun): int
    {
        $fixed = 0;

        foreach ($literalToReal as $literal => $real) {
            /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
            $query = $modelClass::where($column, $literal);
            $rows  = $query->get(['id']);

            if ($rows->isEmpty()) {
                continue;
            }

            $ids = $rows->pluck('id')->implode(', ');
            $this->line("{$modelClass}: {$rows->count()} fila(s) con {$column}='{$literal}' (debería ser '{$real}') — {$prefix}-[{$ids}]");

            if (! $dryRun) {
                $modelClass::where($column, $literal)->update([$column => $real]);
            }

            $fixed += $rows->count();
        }

        return $fixed;
    }
}
