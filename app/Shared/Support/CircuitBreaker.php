<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Simple circuit breaker backed by Redis.
 *
 * States:
 *   closed    — normal operation; failures are counted in a sliding window
 *   open      — too many failures; all calls are rejected immediately
 *   half-open — recovery probe; EXACTLY ONE call is allowed through to test the upstream (SCRUM-1030, R-02)
 *
 * Key layout (Redis):
 *   cb:{name}:failures  — integer, TTL = $windowSeconds
 *   cb:{name}:opened_at — unix timestamp, no TTL (cleared on close)
 *   cb:{name}:probe     — lock of the single half-open probe (SET NX, TTL = PROBE_LOCK_SECONDS)
 *
 * SCRUM-1030 — decisión del Arquitecto: el breaker protege la DISPONIBILIDAD, no es un control de seguridad, así que si Redis falla
 * se comporta como FAIL-OPEN: la llamada se ejecuta sin protección y se avisa en el log (R-01). Que Redis caiga no debe tumbar S3,
 * Claude o la facturación electrónica cuando están sanos.
 */
final class CircuitBreaker
{
    private const DEFAULT_THRESHOLD = 5;
    private const DEFAULT_WINDOW    = 60;   // seconds
    private const DEFAULT_TIMEOUT   = 30;   // seconds before half-open probe
    private const PROBE_LOCK_SECONDS = 10;  // la sonda debe terminar antes; si el proceso muere, el candado expira solo
    private const DEGRADED_LOG_EVERY = 60;  // segundos entre avisos de "Redis caído" por proceso (no inundar el log)

    private static int $lastDegradedLogAt = 0;

    public function __construct(
        private readonly string $name,
        private readonly int    $threshold    = self::DEFAULT_THRESHOLD,
        private readonly int    $windowSeconds = self::DEFAULT_WINDOW,
        private readonly int    $openTimeout   = self::DEFAULT_TIMEOUT,
    ) {}

    /**
     * Execute $callback through the circuit breaker.
     * Throws CircuitOpenException when the circuit is open (or another caller already holds the half-open probe).
     * Re-throws any exception from $callback and records the failure.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        try {
            $isProbe = $this->acquire();
        } catch (CircuitOpenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis no responde: fail-open (R-01). Sin Redis no hay estado que registrar tampoco.
            $this->warnDegraded($e);

            return $callback();
        }

        try {
            $result = $callback();
            $this->safely(fn () => $this->recordSuccess());

            return $result;
        } catch (CircuitOpenException $e) {
            $this->safely(fn () => $this->releaseProbe($isProbe));

            throw $e;
        } catch (\Throwable $e) {
            // Una sonda fallida REABRE el circuito siempre, aunque el contador de fallos ya haya expirado (R-03).
            $this->safely(fn () => $isProbe ? $this->reopen() : $this->recordFailure());

            throw $e;
        }
    }

    /**
     * Decide si la llamada puede pasar. Devuelve true si es LA sonda de semi-abierto, false si el circuito está cerrado.
     *
     * @throws CircuitOpenException circuito abierto o sonda ya en curso por otro llamador
     */
    private function acquire(): bool
    {
        $openedAt = Redis::get($this->openedAtKey());

        if ($openedAt === null || $openedAt === false) {
            return false;
        }

        if (time() - (int) $openedAt < $this->openTimeout) {
            throw new CircuitOpenException("Circuit '{$this->name}' is open.");
        }

        // Semi-abierto: solo el primero que toma el candado hace de sonda (R-02); los demás siguen viendo el circuito abierto.
        if (! Redis::connection()->command('set', [$this->probeKey(), '1', ['NX', 'EX' => self::PROBE_LOCK_SECONDS]])) {
            throw new CircuitOpenException("Circuit '{$this->name}' is half-open: a probe is already in flight.");
        }

        return true;
    }

    private function recordSuccess(): void
    {
        Redis::del($this->openedAtKey());
        Redis::del($this->failuresKey());
        Redis::del($this->probeKey());
    }

    private function recordFailure(): void
    {
        $failures = (int) Redis::incr($this->failuresKey());

        if ($failures === 1) {
            Redis::expire($this->failuresKey(), $this->windowSeconds);
        }

        if ($failures >= $this->threshold) {
            Redis::set($this->openedAtKey(), (string) time());
        }
    }

    private function reopen(): void
    {
        Redis::set($this->openedAtKey(), (string) time());
        Redis::del($this->probeKey());
    }

    private function releaseProbe(bool $isProbe): void
    {
        if ($isProbe) {
            Redis::del($this->probeKey());
        }
    }

    /** Ejecuta una operación de estado del breaker sin dejar que un fallo de Redis tumbe la llamada ya hecha. */
    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->warnDegraded($e);
        }
    }

    private function warnDegraded(\Throwable $e): void
    {
        $now = time();
        if ($now - self::$lastDegradedLogAt < self::DEGRADED_LOG_EVERY) {
            return;
        }
        self::$lastDegradedLogAt = $now;

        Log::warning('CircuitBreaker degradado: Redis no responde, la llamada sigue sin protección (fail-open)', [
            'breaker'   => $this->name,
            'exception' => $e::class,
        ]);
    }

    private function failuresKey(): string
    {
        return "cb:{$this->name}:failures";
    }

    private function openedAtKey(): string
    {
        return "cb:{$this->name}:opened_at";
    }

    private function probeKey(): string
    {
        return "cb:{$this->name}:probe";
    }
}
