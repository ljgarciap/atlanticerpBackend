<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Services\DailyCashCountService;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pre-QA SCRUM-878/879 (2026-09-10, docs/pre-qa/scrum878-879-rebote-20260910.md) — backfill de
 * `daily_cash_counts.snapshot` (migración 2026_09_11_100000) para toda fila que ya no está
 * `abierto` pero fue cerrada ANTES de que existiera el snapshot congelado.
 *
 * No usa el total de HOY (`forDate($fecha)` sin `$asOf`) — eso repetiría exactamente el bug que
 * este backfill corrige (congelaría el total YA contaminado por `commissions:backfill-cash-movements`
 * u otro movimiento retroactivo). En cambio reconstruye el total tal como existía en `cerrado_at`
 * (`DailyCashCountService::forDate($fecha, asOf: cerrado_at)`), filtrando cada fuente por su propio
 * `created_at` — lo que Felix efectivamente vio al cerrar.
 *
 * SCRUM-937 (PQ-934-2, hallazgo de Pre-QA de SCRUM-934) — extiende el mismo comando para un
 * segundo caso: arqueos que YA tienen `snapshot` (congelado antes de que SCRUM-934 agregara
 * `total_facturado_dia`/`total_pasado_a_pedido_dia`) pero a los que les faltan esas 2 claves. Ahí
 * se recalculan esos 2 indicadores "como estaban al cierre" (mismo `$asOf`) y se mergean dentro
 * del snapshot existente SIN reescribir el resto — lo ya congelado no se toca.
 *
 * Idempotente en ambos casos: el primero por `whereNull('snapshot')`, el segundo porque una vez
 * mergeadas las 2 claves, `snapshot->>'total_facturado_dia' IS NULL` deja de matchear esa fila.
 * Corre en dev/test dentro del batch; en prod en ventana coordinada con Luis, igual que
 * `commissions:backfill-cash-movements`.
 */
final class AdminContBackfillDailyCashCountSnapshotsCommand extends Command
{
    protected $signature = 'admin-contab:backfill-daily-cash-count-snapshots
        {--tenant=atlantic : Slug del tenant}
        {--dry-run : Solo muestra qué filas se congelarían/completarían y con qué total, sin escribir nada}';

    protected $description = 'Pre-QA SCRUM-878/879 + SCRUM-937 — congela el snapshot de arqueos cerrados sin uno, y completa los indicadores del día (SCRUM-934) en snapshots que ya existían sin ellos, ambos reconstruidos a la fecha de cierre';

    public function handle(DailyCashCountService $service): int
    {
        $tenantSlug = (string) $this->option('tenant');
        $dryRun     = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $pending = AdminContDailyCashCount::where('estado', '!=', AdminContDailyCashCount::ESTADO_ABIERTO)
            ->where(function ($q) {
                // Caso 1 (SCRUM-878/879): nunca se congeló. Caso 2 (SCRUM-937): ya tiene
                // snapshot pero le faltan los indicadores de SCRUM-934 — el operador `->>` de
                // jsonb devuelve NULL tanto si la clave no existe como si el snapshot es NULL.
                $q->whereNull('snapshot')
                    ->orWhereRaw("snapshot->>'total_facturado_dia' IS NULL");
            })
            ->orderBy('fecha')
            ->get();

        if ($pending->isEmpty()) {
            $this->info("[{$tenantSlug}] Nada que congelar/completar — todo arqueo no-abierto ya tiene snapshot con los indicadores del día.");

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($pending as $record) {
            // No debería pasar (estado != abierto implica que pasó por close()), pero un registro
            // legado corrupto no debe tumbar el resto del backfill — se salta con aviso explícito.
            if ($record->cerrado_at === null) {
                $this->warn("  omitido fecha={$record->fecha->toDateString()} id={$record->id} — sin cerrado_at, no se puede reconstruir el instante del cierre.");

                continue;
            }

            $asOf = CarbonImmutable::parse($record->cerrado_at);

            if ($record->snapshot === null) {
                $snapshot = $service->forDate($record->fecha->toDateString(), $asOf);
                $this->line("  fecha={$record->fecha->toDateString()} id={$record->id} estado={$record->estado} snapshot completo, total_neto={$snapshot['total_neto']} (cerrado {$asOf->toIso8601String()})");

                if (! $dryRun) {
                    $record->update(['snapshot' => $snapshot]);
                }
                $count++;

                continue;
            }

            // SCRUM-937 — el snapshot ya existía (congelado antes de SCRUM-934); se completan
            // solo los 2 indicadores nuevos, reconstruidos al mismo `cerrado_at`, sin tocar el
            // resto de lo ya congelado.
            $indicators = $service->forDate($record->fecha->toDateString(), $asOf);
            $merged     = [
                ...$record->snapshot,
                'total_facturado_dia'       => $indicators['total_facturado_dia'],
                'total_pasado_a_pedido_dia' => $indicators['total_pasado_a_pedido_dia'],
            ];

            $this->line("  fecha={$record->fecha->toDateString()} id={$record->id} estado={$record->estado} indicadores agregados: facturado={$indicators['total_facturado_dia']} pasado_a_pedido={$indicators['total_pasado_a_pedido_dia']} (cerrado {$asOf->toIso8601String()})");

            if (! $dryRun) {
                $record->update(['snapshot' => $merged]);
            }
            $count++;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}[{$tenantSlug}] {$count} arqueo(s) congelado(s)/completado(s).");

        return self::SUCCESS;
    }
}
