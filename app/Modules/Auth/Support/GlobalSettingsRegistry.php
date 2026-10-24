<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * SCRUM-944/945 (Pre-QA hallazgo E) — lista blanca ÚNICA de claves de `GlobalSetting` editables
 * desde la vista consolidada de superadmin. Una clave fuera de esta lista (ej.
 * `anthropic_api_key`, un secreto) nunca se lista ni se edita por `GlobalSettingsController`.
 *
 * `default` replica el default de cada consumidor real (DocumentController, ArchiveRead-
 * NotificationsJob, ArchiveAuditLogsJob) — no se cambia ningún valor vigente. `min` de
 * `audit_logs.retention_months` = 12: decisión del CTO 2026-08-30 (SCRUM-812/REQ-636, retención
 * "12 meses+") — bajarla purgaría audit_logs antes del mínimo aprobado. `max` de
 * `documents.max_size_mb` = 500: mismo tope que `SettingsController::updateDocumentsMaxSize()`.
 */
final class GlobalSettingsRegistry
{
    /**
     * @var array<string, array{type: string, min: int, max: int, default: int, group: string}>
     */
    private const KEYS = [
        'documents.max_size_mb' => [
            'type' => 'integer', 'min' => 1, 'max' => 500, 'default' => 500, 'group' => 'documents',
        ],
        'notifications.archive_read_after_days' => [
            'type' => 'integer', 'min' => 1, 'max' => 365, 'default' => 30, 'group' => 'notifications',
        ],
        'audit_logs.retention_months' => [
            'type' => 'integer', 'min' => 12, 'max' => 120, 'default' => 12, 'group' => 'audit_logs',
        ],
        // Reset administrativo de contraseñas (H2/S2 del cierre de la revisión de seguridad). Horas que dura la contraseña
        // temporal (= correo) tras un reset: pasado ese plazo la cuenta con el cambio obligatorio pendiente queda caducada.
        'password_reset_expiration_hours' => [
            'type' => 'integer', 'min' => 1, 'max' => 720, 'default' => 48, 'group' => 'security',
        ],
        // Interruptor del reset administrativo (comando y API). `default` es DINÁMICO: apagado (0) en producción y encendido (1) en
        // el resto de entornos (ver `defaultFor()`); un valor guardado lo reemplaza.
        'password_reset_enabled' => [
            'type' => 'boolean', 'min' => 0, 'max' => 1, 'default' => 1, 'group' => 'security',
        ],
        // R3-1: resets individuales de personas normales por hora y por operador SIN contraseña; desde el siguiente se exige
        // `operator_password`. 0 = siempre exige contraseña. Las cuentas de máximo privilegio la exigen siempre.
        'password_reset_free_per_hour' => [
            'type' => 'integer', 'min' => 0, 'max' => 100, 'default' => 5, 'group' => 'security',
        ],
        // SCRUM-1158 — «Ver como usuario» (ADR-010). Los lee `App\Modules\Auth\Services\ImpersonationSettings`; ahí está el significado de cada uno.
        // Duración máxima de la sesión (minutos); el token no se renueva.
        'impersonation_max_minutes' => [
            'type' => 'integer', 'min' => 5, 'max' => 120, 'default' => 30, 'group' => 'impersonation',
        ],
        // Largo mínimo del motivo obligatorio al iniciar la sesión.
        'impersonation_reason_min_length' => [
            'type' => 'integer', 'min' => 5, 'max' => 200, 'default' => 10, 'group' => 'impersonation',
        ],
        // Alertas de uso inusual (SecurityAnalysisJob): sesiones de un actor en un día / personas distintas vistas en una hora /
        // intentos de escritura bloqueados dentro de una sola sesión.
        'impersonation_alert_sessions_per_day' => [
            'type' => 'integer', 'min' => 1, 'max' => 50, 'default' => 5, 'group' => 'impersonation',
        ],
        'impersonation_alert_targets_per_hour' => [
            'type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 3, 'group' => 'impersonation',
        ],
        'impersonation_alert_blocked_writes_per_session' => [
            'type' => 'integer', 'min' => 1, 'max' => 500, 'default' => 20, 'group' => 'impersonation',
        ],
    ];

    /**
     * Ajustes cuyo cambio exige la contraseña del operador (`operator_password`, R3-6): quien los controla decide cuándo y por
     * cuánto tiempo se puede resetear contraseñas, así que un token de superadmin robado no debe poder moverlos solo.
     */
    private const REAUTH_KEYS = [self::PASSWORD_RESET_ENABLED, self::PASSWORD_RESET_EXPIRATION_HOURS, ...self::IMPERSONATION_KEYS];

    /**
     * SCRUM-1158 — parámetros de «Ver como usuario». Aflojarlos (alargar la sesión, subir los umbrales de alerta) es justo lo que querría un
     * abuso interno, así que cambiarlos exige la contraseña del operador como los del reset de contraseñas. Aprobado por Luis.
     */
    public const IMPERSONATION_KEYS = [
        'impersonation_max_minutes',
        'impersonation_reason_min_length',
        'impersonation_alert_sessions_per_day',
        'impersonation_alert_targets_per_hour',
        'impersonation_alert_blocked_writes_per_session',
    ];

    /** Clave del interruptor del reset administrativo (la política que lo lee vive en PasswordResetPolicy). */
    public const PASSWORD_RESET_ENABLED = 'password_reset_enabled';

    /** Clave de la caducidad (horas) de la contraseña temporal. */
    public const PASSWORD_RESET_EXPIRATION_HOURS = 'password_reset_expiration_hours';

    /** Clave del tope de resets individuales por hora sin contraseña del operador. */
    public const PASSWORD_RESET_FREE_PER_HOUR = 'password_reset_free_per_hour';

    /**
     * Ámbito del cupo de contraseñas incorrectas de `OperatorReauth` para este ajuste. Los de impersonación usan el de «Ver como usuario»
     * (condición C3): no gastan el cupo del reset de contraseñas ni al revés. El resto sigue con el ámbito de siempre.
     */
    public static function reauthScope(string $key): string
    {
        return in_array($key, self::IMPERSONATION_KEYS, true)
            ? \App\Modules\Auth\Services\OperatorReauth::SCOPE_IMPERSONATION
            : \App\Modules\Auth\Services\OperatorReauth::SCOPE_PASSWORD_RESET;
    }

    /** ¿Cambiar este ajuste exige la contraseña del operador? */
    public static function requiresReauth(string $key): bool
    {
        return in_array($key, self::REAUTH_KEYS, true);
    }

    /** @return array<string, array{type: string, min: int, max: int, default: int, group: string}> */
    public static function all(): array
    {
        $keys = [];
        foreach (self::KEYS as $key => $meta) {
            $keys[$key] = ['default' => self::defaultFor($key)] + $meta;
        }

        return $keys;
    }

    /** @return array{type: string, min: int, max: int, default: int, group: string}|null */
    public static function find(string $key): ?array
    {
        $meta = self::KEYS[$key] ?? null;

        return $meta === null ? null : ['default' => self::defaultFor($key)] + $meta;
    }

    /**
     * Default efectivo de una clave. Casi todas tienen un default fijo; `password_reset_enabled` depende del entorno: en
     * producción el reset administrativo arranca APAGADO y un superadmin lo enciende solo durante la ventana acordada.
     */
    public static function defaultFor(string $key): int
    {
        if ($key === self::PASSWORD_RESET_ENABLED) {
            return app()->environment('production') ? 0 : 1;
        }

        return self::KEYS[$key]['default'] ?? 0;
    }

    /** Clave i18n del label en el frontend: `globalSettings.globalTab.labels.<key con "." → "_">`. */
    public static function labelKey(string $key): string
    {
        return 'globalSettings.globalTab.labels.' . str_replace('.', '_', $key);
    }

    /** Clave de cache que ya usan los consumidores (`global:{key}`) — se invalida al editar. */
    public static function cacheKey(string $key): string
    {
        return 'global:' . $key;
    }
}
