<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Simple circuit breaker backed by Redis.
 *
 * States:
 *   closed    — normal operation; failures are counted in a sliding window
 *   open      — too many failures; all calls are rejected immediately
 *   half-open — recovery probe; one call allowed through to test the upstream
 *
 * Key layout (Redis):
 *   cb:{name}:failures  — integer, TTL = $windowSeconds
 *   cb:{name}:opened_at — unix timestamp, no TTL (cleared on close)
 */
final class CircuitBreaker
{
    private const DEFAULT_THRESHOLD = 5;
    private const DEFAULT_WINDOW    = 60;   // seconds
    private const DEFAULT_TIMEOUT   = 30;   // seconds before half-open probe

    public function __construct(
        private readonly string $name,
        private readonly int    $threshold    = self::DEFAULT_THRESHOLD,
        private readonly int    $windowSeconds = self::DEFAULT_WINDOW,
        private readonly int    $openTimeout   = self::DEFAULT_TIMEOUT,
    ) {}

    /**
     * Execute $callback through the circuit breaker.
     * Throws CircuitOpenException when the circuit is open.
     * Re-throws any exception from $callback and records the failure.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        if ($this->isOpen()) {
            throw new CircuitOpenException("Circuit '{$this->name}' is open.");
        }

        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (CircuitOpenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    private function isOpen(): bool
    {
        $openedAt = Redis::get($this->openedAtKey());

        if ($openedAt === null) {
            return false;
        }

        // Transition to half-open after timeout
        if (time() - (int) $openedAt >= $this->openTimeout) {
            return false; // allow one probe
        }

        return true;
    }

    private function recordSuccess(): void
    {
        Redis::del($this->openedAtKey());
        Redis::del($this->failuresKey());
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

    private function failuresKey(): string
    {
        return "cb:{$this->name}:failures";
    }

    private function openedAtKey(): string
    {
        return "cb:{$this->name}:opened_at";
    }
}
