<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use App\Modules\Auth\Jobs\ArchiveAuditLogsJob;
use App\Modules\Notifications\Jobs\ArchiveReadNotificationsJob;
use App\Shared\Support\AiUsageAuditor;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\InjectsFaults;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-965 — hallazgo R-09: el store `redis` de cache devuelve los ENTEROS como texto al leerlos del cache. El cache `array` de
 * los tests los conserva como int, así que ningún test lo veía; en dev provocó el único job fallido (`failed_jobs` id 1,
 * 2026-10-03 03:15, TypeError en `ArchiveReadNotificationsJob::resolveArchiveAfterDays()` por `declare(strict_types=1)`).
 *
 * TEST DE CARACTERIZACIÓN: fija el defecto actual. Cuando se corrija (castear a int tras `Cache::remember`), el test fallará a
 * propósito: pasarlo a `assertNull($thrown)` en el mismo commit.
 */
final class RedisCacheTypesTest extends FeatureTestCase
{
    use InjectsFaults;

    private const KEY = 'global:notifications.archive_read_after_days';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useRedisCache();
        Cache::forget(self::KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(self::KEY);
        parent::tearDown();
    }

    public function test_el_store_redis_devuelve_un_entero_cacheado_como_texto(): void
    {
        Cache::put('chaos:tipo-entero', 30, 60);

        try {
            $this->assertSame('30', Cache::get('chaos:tipo-entero'), 'el cache redis devuelve "30" (string), no 30 (int)');
        } finally {
            Cache::forget('chaos:tipo-entero');
        }
    }

    public function test_r09_archive_read_notifications_job_no_falla_al_leer_el_entero_del_cache(): void
    {
        $job = new ArchiveReadNotificationsJob('atlantic');

        $job->handle();   // 1.ª corrida: calcula el valor (int) y lo guarda en el cache
        $job->handle();   // 2.ª corrida dentro del TTL de 300 s: lee "30" (string) del cache; el cast (int) evita el TypeError

        $this->assertSame(30, $this->invoke($job, 'resolveArchiveAfterDays'));
    }

    public function test_r09_archive_audit_logs_job_devuelve_entero_desde_el_cache(): void
    {
        $job = new ArchiveAuditLogsJob('atlantic');

        $this->invoke($job, 'resolveRetentionMonths');   // puebla el cache
        $this->assertIsInt($this->invoke($job, 'resolveRetentionMonths'));
    }

    public function test_r09_ai_usage_auditor_devuelve_entero_desde_el_cache(): void
    {
        $method = new \ReflectionMethod(AiUsageAuditor::class, 'previewChars');
        $method->setAccessible(true);

        $method->invoke(null);   // puebla el cache
        $this->assertIsInt($method->invoke(null));
    }

    private function invoke(object $job, string $method): mixed
    {
        $m = new \ReflectionMethod($job, $method);
        $m->setAccessible(true);

        return $m->invoke($job);
    }
}
