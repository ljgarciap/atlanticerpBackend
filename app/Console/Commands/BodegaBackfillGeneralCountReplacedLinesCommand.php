<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\GeneralCountRequestLine;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * SCRUM-800 — backfill de líneas de conteo general huérfanas. `GeneralCountController::
 * findConflictingLines()` colapsaba a UNA línea por producto (`keyBy`) al marcar `replaced_by_id`
 * tras confirmar un reemplazo (ver fix en `markConflictingLinesReplaced()` de ese controller) — si
 * hubo más de un ciclo de conteo sin resolver para el mismo producto+bodega, las líneas anteriores
 * a la última quedaban huérfanas (`replaced_by_id` NULL) y seguían bloqueando Solicitudes de
 * Ajuste/Conteos nuevos para siempre, aunque ya no reflejaran el estado real más reciente.
 *
 * Este comando marca como reemplazada (`replaced_by_id` = línea más reciente del mismo
 * producto+bodega, sin importar su estado) toda línea huérfana que ya fue superada por una línea
 * posterior — mismo criterio de "huérfana" que `GeneralCountController::conflictingLinesQuery()`
 * (diferencia != 0, estado sin resolver, replaced_by_id NULL), evaluado por par
 * (catalog_product_id, warehouse_id). La línea más reciente de cada par NUNCA se marca a sí misma
 * — si sigue sin resolver con diferencia real, es un bloqueo legítimo vigente, no huérfano.
 *
 * Idempotente: solo toca filas que hoy cumplen la condición de huérfana; correrlo de nuevo tras
 * una corrida exitosa no encuentra nada que rehacer.
 */
final class BodegaBackfillGeneralCountReplacedLinesCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:backfill-general-count-replaced-lines
        {--tenant=illuminations : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-800 — marca como reemplazadas las líneas de conteo general huérfanas que quedaron bloqueando ajustes/conteos por el bug de keyBy() en GeneralCountController';

    public function handle(): int
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

        /** @var Collection<string, Collection<int, GeneralCountRequestLine>> $orphansByGroup */
        $orphansByGroup = GeneralCountRequestLine::query()
            ->whereNull('replaced_by_id')
            ->whereNotNull('diferencia')
            ->where('diferencia', '!=', 0)
            ->whereHas('request', fn ($q) => $q->whereIn('estado', [
                GeneralCountRequest::ESTADO_EVALUADO,
                GeneralCountRequest::ESTADO_PENDIENTE_APROBACION,
            ]))
            ->with('request')
            ->orderBy('catalog_product_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (GeneralCountRequestLine $line) => $line->catalog_product_id.':'.$line->request->warehouse_id);

        $backfilled = 0;
        $skippedIsLatest = 0;

        foreach ($orphansByGroup as $group) {
            /** @var GeneralCountRequestLine $first */
            $first = $group->first();

            $latest = GeneralCountRequestLine::query()
                ->where('catalog_product_id', $first->catalog_product_id)
                ->whereHas('request', fn ($q) => $q->where('warehouse_id', $first->request->warehouse_id))
                ->orderByDesc('id')
                ->first();

            foreach ($group as $line) {
                if ($latest === null || $latest->id === $line->id) {
                    $skippedIsLatest++;

                    continue;
                }

                $this->line("GeneralCountRequestLine #{$line->id} (producto {$line->catalog_product_id}, bodega {$first->request->warehouse_id}) — replaced_by_id = #{$latest->id}");

                if (! $dryRun) {
                    $line->update(['replaced_by_id' => $latest->id]);
                }

                $backfilled++;
            }
        }

        $this->info(
            ($dryRun ? 'Dry-run — ' : '')
            ."{$backfilled} líneas huérfanas marcadas como reemplazadas. {$skippedIsLatest} omitidas (son la línea más reciente de su producto+bodega — si sigue sin resolver, es bloqueo legítimo vigente)."
        );

        return self::SUCCESS;
    }
}
