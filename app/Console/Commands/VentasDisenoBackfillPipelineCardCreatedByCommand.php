<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-884 — backfill de `created_by` para tarjetas creadas antes de que el campo existiera.
 * Fuente primaria: `audit_logs` — `LogsAuditTrail` (activo en `PipelineCard` desde SCRUM-789)
 * registra `user_id` en cada evento `ventas_diseno.pipeline_card.created`, con el id real de
 * quien hizo el request de creación (ver `AuditService::resolveUserId()`), exactamente el dato
 * que `created_by` necesita. Fallback (RN explícita del ticket: "tomando el responsable original
 * registrado al momento de creación, si el dato está disponible"): tarjetas creadas ANTES de
 * SCRUM-789 (sin fila de auditoría) usan `owner_id` actual como mejor aproximación disponible.
 *
 * Idempotente: solo toca filas con `created_by IS NULL`; correrlo de nuevo tras una corrida
 * exitosa no encuentra nada que rehacer.
 */
final class VentasDisenoBackfillPipelineCardCreatedByCommand extends Command
{
    /** @var string */
    protected $signature = 'ventas_diseno:backfill-pipeline-card-created-by
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-884 — backfill de created_by en pipeline_cards desde audit_logs (fallback: owner_id actual)';

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

        $fromAudit = 0;
        $fromOwner = 0;

        foreach (PipelineCard::whereNull('created_by')->get() as $card) {
            $creatorId = AuditLog::query()
                ->where('entity_type', 'pipeline_card')
                ->where('action', 'ventas_diseno.pipeline_card.created')
                ->where('entity_id', (string) $card->id)
                ->whereNotNull('user_id')
                ->orderBy('created_at')
                ->value('user_id');

            if ($creatorId !== null) {
                $this->line("PipelineCard #{$card->id} — created_by={$creatorId} (desde audit_logs)");
                $fromAudit++;
            } else {
                $creatorId = $card->owner_id;
                $this->line("PipelineCard #{$card->id} — created_by={$creatorId} (fallback: owner_id actual, sin audit_logs)");
                $fromOwner++;
            }

            if (! $dryRun) {
                $card->update(['created_by' => $creatorId]);
            }
        }

        $this->info(
            ($dryRun ? 'Dry-run — ' : '')
            ."{$fromAudit} backfilleados desde audit_logs, {$fromOwner} desde fallback owner_id."
        );

        return self::SUCCESS;
    }
}
