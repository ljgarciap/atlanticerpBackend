<?php

declare(strict_types=1);

namespace App\Modules\Auth\Jobs;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\GlobalSetting;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-812 (REQ-636, Epic SCRUM-788) — archiva a S3 y purga de Postgres las filas de
 * audit_logs más viejas que auth.settings.audit_logs_retention_months (default 12, GlobalSetting
 * — nunca hardcodeado, regla dura del proyecto). Nunca borra sin haber subido antes: cada chunk
 * se sube completo a s3-private y solo se purga ESE chunk si el upload fue exitoso — si un chunk
 * falla, el resto queda para la próxima corrida (dailyAt, ver routes/console.php), sin perder
 * datos ni bloquear los chunks anteriores que sí se archivaron.
 */
final class ArchiveAuditLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DEFAULT_RETENTION_MONTHS = 12;
    private const CACHE_KEY                = 'global:audit_logs.retention_months';
    private const CACHE_TTL_SECONDS        = 300;
    private const CHUNK_SIZE               = 500;
    // Tope por corrida — un backlog más grande se sigue procesando en corridas sucesivas
    // (dailyAt), evita que un solo job quede corriendo indefinidamente si hay millones de filas.
    private const MAX_CHUNKS_PER_RUN = 20;

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
            $this->archiveOldRows($tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function archiveOldRows(string $tenantSlug): void
    {
        $cutoff = now()->subMonths($this->resolveRetentionMonths());

        for ($chunk = 0; $chunk < self::MAX_CHUNKS_PER_RUN; $chunk++) {
            $rows = AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $uploaded = $this->uploadChunk($tenantSlug, $rows);

            if (! $uploaded) {
                // No se borra nada de este chunk — la próxima corrida lo vuelve a intentar.
                return;
            }

            AuditLog::query()->whereIn('id', $rows->pluck('id'))->delete();
        }
    }

    /** @param \Illuminate\Support\Collection<int, AuditLog> $rows */
    private function uploadChunk(string $tenantSlug, \Illuminate\Support\Collection $rows): bool
    {
        $key = sprintf(
            'audit-logs-archive/%s/%s-%s.json',
            $tenantSlug,
            now()->format('Y-m-d'),
            Str::uuid(),
        );

        $payload = $rows->map(fn (AuditLog $log) => $log->toArray())->all();

        try {
            $result = (new CircuitBreaker('s3-private'))->call(
                fn () => Storage::disk('s3-private')->put($key, json_encode($payload, JSON_THROW_ON_ERROR)),
            );

            if ($result !== true) {
                Log::warning('audit_logs.archive.upload_failed', ['tenant' => $tenantSlug, 'key' => $key]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('audit_logs.archive.upload_failed', [
                'tenant' => $tenantSlug,
                'key'    => $key,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function resolveRetentionMonths(): int
    {
        /** @var int $months */
        $months = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): int {
            try {
                $setting = GlobalSetting::find('audit_logs.retention_months');

                if ($setting !== null && $setting->value !== '') {
                    return (int) $setting->value;
                }
            } catch (\Throwable) {
                // La tabla puede no existir todavía durante migraciones — fallback al default
            }

            return self::DEFAULT_RETENTION_MONTHS;
        });

        // El store redis devuelve los enteros como texto (R-09): sin el cast, `strict_types` lanza TypeError en la 2.ª lectura.
        return (int) $months;
    }
}
