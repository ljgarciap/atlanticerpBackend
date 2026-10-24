<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Auto-archiva (soft — nunca DELETE) notificaciones ya leídas después de N días
 * (global_settings.notifications.archive_read_after_days, default 30). Las no leídas nunca
 * se archivan solas. Despachado por tenant desde ArchiveReadNotificationsCommand (Horizon,
 * diario) — mismo patrón que SecurityAnalysisJob.
 */
final class ArchiveReadNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DEFAULT_DAYS     = 30;
    private const CACHE_KEY        = 'global:notifications.archive_read_after_days';
    private const CACHE_TTL_SECONDS = 300;

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

    public function handle(): void
    {
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();

        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        try {
            $days = $this->resolveArchiveAfterDays();

            Notification::query()
                ->whereNotNull('read_at')
                ->whereNull('archived_at')
                ->where('read_at', '<', now()->subDays($days))
                ->update(['archived_at' => now()]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function resolveArchiveAfterDays(): int
    {
        /** @var int $days */
        $days = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): int {
            try {
                $setting = GlobalSetting::find('notifications.archive_read_after_days');

                if ($setting !== null && $setting->value !== '') {
                    return (int) $setting->value;
                }
            } catch (\Throwable) {
                // La tabla puede no existir todavía durante migraciones — fallback al default
            }

            return self::DEFAULT_DAYS;
        });

        // El store redis devuelve los enteros como texto (R-09): sin el cast, `strict_types` lanza TypeError en la 2.ª lectura.
        return (int) $days;
    }
}
