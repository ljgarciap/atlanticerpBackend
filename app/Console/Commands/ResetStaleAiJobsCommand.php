<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AI\Models\AiAnalysis;
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

        $stale = AiAnalysis::whereIn('status', [AiAnalysis::STATUS_PENDING, AiAnalysis::STATUS_RUNNING])
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($stale as $job) {
            $job->update([
                'status' => AiAnalysis::STATUS_FAILED,
                'error'  => "Job estancado — sin progreso por más de {$minutes} minutos. Reset automático.",
            ]);
        }

        Log::warning('ai:reset-stale: jobs estancados reseteados', [
            'count'   => $stale->count(),
            'ids'     => $stale->pluck('id')->all(),
            'minutes' => $minutes,
        ]);

        $this->warn("Reseteados {$stale->count()} job(s) estancados.");

        return self::SUCCESS;
    }
}
