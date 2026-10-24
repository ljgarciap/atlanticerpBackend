<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;

/**
 * SCRUM-965 — inyección de fallos de infraestructura SIN tocar contenedores.
 *
 * Simula "Redis caído" apuntando TODAS las conexiones Redis a 127.0.0.1:1 (puerto sin servicio) y reconstruyendo cada
 * consumidor que ya había copiado la configuración o la conexión. Los contenedores del stack local los comparten otras
 * sesiones de trabajo y el gate, así que ningún test debe parar Redis de verdad (eso es `infra/chaos/redis-down.sh`, manual).
 *
 * Trampas conocidas (cada una costó un intento fallido en el prototipo):
 *  - cambiar solo `config()` NO basta: `RedisManager` copia la configuración al arrancar → hay que olvidar la instancia `redis`;
 *  - el cache usa la conexión `cache`, distinta de `default`: hay que romper las dos;
 *  - `REDIS_URL`, si existe, manda sobre host/puerto: se anula;
 *  - el store `redis` de cache y el `RateLimiter` guardan la fábrica vieja: también se reconstruyen, y el facade `RateLimiter`
 *    conserva su instancia resuelta aparte (`clearResolvedInstance`).
 *
 * La restauración corre en `tearDownInjectsFaults()` (hook automático de los traits de Laravel) aunque el test falle.
 */
trait InjectsFaults
{
    /** @var array<string, mixed>|null configuración de Redis original mientras hay un fallo inyectado */
    private ?array $faultOriginalRedis = null;

    private ?string $faultOriginalCacheDefault = null;

    /** Redis no responde (conexión rechazada) para `Redis::` y para el store `redis` de cache. */
    protected function redisDown(): void
    {
        if ($this->faultOriginalRedis !== null) {
            return;
        }

        $this->faultOriginalRedis = (array) config('database.redis');

        foreach (array_keys($this->faultOriginalRedis) as $name) {
            if (in_array($name, ['client', 'options', 'clusters'], true) || ! is_array($this->faultOriginalRedis[$name])) {
                continue;
            }
            config([
                "database.redis.{$name}.url"  => null,
                "database.redis.{$name}.host" => '127.0.0.1',
                "database.redis.{$name}.port" => 1,
                // sin reintentos: el test debe fallar rápido, no esperar el backoff de producción
                "database.redis.{$name}.max_retries" => 0,
            ]);
        }

        $this->rebuildRedisConsumers();
    }

    /** Redis vuelve (restaura la configuración original). */
    protected function redisUp(): void
    {
        if ($this->faultOriginalRedis === null) {
            return;
        }

        config(['database.redis' => $this->faultOriginalRedis]);
        $this->faultOriginalRedis = null;
        $this->rebuildRedisConsumers();
    }

    /**
     * Hace que cache y RateLimiter usen Redis (en test son `array`, ver phpunit.xml). Imprescindible para reproducir
     * lo que pasa en producción con los límites de intentos de Auth.
     */
    protected function useRedisCache(): void
    {
        $this->faultOriginalCacheDefault ??= (string) config('cache.default');
        config(['cache.default' => 'redis']);
        $this->rebuildRedisConsumers();
    }

    protected function tearDownInjectsFaults(): void
    {
        $this->redisUp();

        if ($this->faultOriginalCacheDefault !== null) {
            config(['cache.default' => $this->faultOriginalCacheDefault]);
            $this->faultOriginalCacheDefault = null;
            $this->rebuildRedisConsumers();
        }
    }

    private function rebuildRedisConsumers(): void
    {
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');

        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        Cache::clearResolvedInstance('cache');

        app()->forgetInstance(RateLimiter::class);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    }
}
