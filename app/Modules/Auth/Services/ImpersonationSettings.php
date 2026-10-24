<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Support\GlobalSettingsRegistry;

/**
 * SCRUM-1158 — parámetros de «Ver como usuario». Viven en `GlobalSetting` y se editan desde la vista de ajustes globales de superadmin
 * (`GET/PUT /api/admin/global-settings`, grupo `impersonation`; regla del workspace: ningún umbral de negocio como constante de código, se
 * ajustan sin deploy). El rango y el valor por defecto de cada clave salen de `GlobalSettingsRegistry`, la única fuente. Cada valor se acota a
 * su rango aunque la fila se haya editado a mano.
 *
 * - `impersonation_max_minutes` (30, 5–120): duración máxima de la sesión; el token no se renueva.
 * - `impersonation_reason_min_length` (10, 5–200): largo mínimo del motivo obligatorio.
 * - `impersonation_alert_sessions_per_day` (5, 1–50): sesiones de un mismo actor en un día que disparan una alerta.
 * - `impersonation_alert_targets_per_hour` (3, 1–20): personas distintas vistas por un actor en una hora que disparan una alerta.
 * - `impersonation_alert_blocked_writes_per_session` (20, 1–500): intentos de escritura bloqueados en una sola sesión que disparan una
 *   alerta (señal de un guion automatizado probando endpoints; condición C7 del threat model).
 */
final class ImpersonationSettings
{
    public const MAX_MINUTES                  = 'impersonation_max_minutes';
    public const REASON_MIN_LENGTH            = 'impersonation_reason_min_length';
    public const ALERT_SESSIONS_PER_DAY       = 'impersonation_alert_sessions_per_day';
    public const ALERT_TARGETS_PER_HOUR       = 'impersonation_alert_targets_per_hour';
    public const ALERT_BLOCKED_WRITES_SESSION = 'impersonation_alert_blocked_writes_per_session';

    /** @var list<string> */
    public const KEYS = [
        self::MAX_MINUTES,
        self::REASON_MIN_LENGTH,
        self::ALERT_SESSIONS_PER_DAY,
        self::ALERT_TARGETS_PER_HOUR,
        self::ALERT_BLOCKED_WRITES_SESSION,
    ];

    public function maxMinutes(): int
    {
        return $this->value(self::MAX_MINUTES);
    }

    public function reasonMinLength(): int
    {
        return $this->value(self::REASON_MIN_LENGTH);
    }

    public function alertSessionsPerDay(): int
    {
        return $this->value(self::ALERT_SESSIONS_PER_DAY);
    }

    public function alertTargetsPerHour(): int
    {
        return $this->value(self::ALERT_TARGETS_PER_HOUR);
    }

    public function alertBlockedWritesPerSession(): int
    {
        return $this->value(self::ALERT_BLOCKED_WRITES_SESSION);
    }

    /** @return array<string, int> clave => valor vigente. */
    public function all(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $this->value($key);
        }

        return $out;
    }

    private function value(string $key): int
    {
        $meta  = GlobalSettingsRegistry::find($key) ?? ['min' => 1, 'max' => PHP_INT_MAX, 'default' => 1];
        $raw   = GlobalSetting::find($key)?->value;
        $value = is_numeric($raw) ? (int) $raw : $meta['default'];

        return max($meta['min'], min($meta['max'], $value));
    }
}
