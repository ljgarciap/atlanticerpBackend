<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesa notification_sends cuyo scheduled_at ya venció (circulares programadas a futuro,
 * Fase 3). Los sends inmediatos (scheduled_at null) ya se entregan en el momento de
 * NotificationService::send() — este job es solo para el caso "programado". Despachado por
 * tenant desde DispatchScheduledNotificationSendsCommand (Horizon, cada N minutos).
 */
final class DispatchScheduledSendsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * SCRUM-1036 (R-08): espera creciente entre reintentos (estándar del proyecto, igual que BaseAiJob), en vez del reintento inmediato.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(private readonly string $tenantSlug)
    {
    }

    public function handle(NotificationService $notifications): void
    {
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();

        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        try {
            $due = NotificationSend::query()
                ->whereNull('sent_at')
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($due as $send) {
                $notifications->deliver($send);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
