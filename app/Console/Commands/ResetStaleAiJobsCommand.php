<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AI\Models\AiAnalysis;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ResetStaleAiJobsCommand extends Command
{
    protected $signature   = 'ai:reset-stale {--minutes=10 : Minutes before a pending/running job is considered stale}';
    protected $description = 'Reset AI jobs stuck in pending/running state for too long';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff  = now()->subMinutes($minutes);
        $total   = 0;

        // AiAnalysis::getTable() resuelve el schema del tenant activo — sin iterar tenants acá
        // (mismo patrón que DispatchScheduledNotificationSendsCommand), la query explota con
        // "No active tenant when querying ai_analyses" y el comando nunca corre (SCRUM-927,
        // hallazgo lateral 2026-09-17 — 598 fallos silenciosos acumulados en prod).
        foreach (Tenant::all() as $tenant) {
            $tenant->makeCurrent();

            try {
                $stale = AiAnalysis::whereIn('status', [AiAnalysis::STATUS_PENDING, AiAnalysis::STATUS_RUNNING])
                    ->where('updated_at', '<', $cutoff)
                    ->get();

                foreach ($stale as $job) {
                    $job->update([
                        'status' => AiAnalysis::STATUS_FAILED,
                        'error'  => "Job estancado — sin progreso por más de {$minutes} minutos. Reset automático.",
                    ]);
                }

                if ($stale->isNotEmpty()) {
                    Log::warning('ai:reset-stale: jobs estancados reseteados', [
                        'tenant'  => $tenant->slug,
                        'count'   => $stale->count(),
                        'ids'     => $stale->pluck('id')->all(),
                        'minutes' => $minutes,
                    ]);
                }

                $total += $stale->count();
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info("Reseteados {$total} job(s) estancados en total.");

        return self::SUCCESS;
    }
}
