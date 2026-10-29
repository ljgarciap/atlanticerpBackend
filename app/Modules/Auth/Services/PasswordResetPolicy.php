<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Support\GlobalSettingsRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Política del reset administrativo de contraseñas, leída de los ajustes globales (`global_settings`, editables por superadmin en
 * `/api/admin/global-settings`):
 *
 * - `password_reset_expiration_hours` (1..720, default 168): horas que dura el enlace de activación de una cuenta nueva o reiniciada (Fase 12c; antes, la contraseña temporal = correo). Una cuenta con
 *   `must_change_password` activo cuyo `password_reset_at` es más antiguo que ese plazo está CADUCADA: no puede iniciar sesión ni
 *   usar un token vivo hasta otro reset o un "olvidé mi contraseña" (H2).
 * - `password_reset_enabled`: interruptor del reset administrativo (comando y API). Si no hay valor guardado, está APAGADO en
 *   producción y encendido en el resto de entornos.
 *
 * Los dos valores se cachean 5 minutos con la clave `global:{key}` que `GlobalSettingsController::update()` invalida al editar.
 */
final class PasswordResetPolicy
{
    private const CACHE_SECONDS = 300;

    public function expirationHours(): int
    {
        $meta   = GlobalSettingsRegistry::find(GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS);
        $stored = $this->stored(GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS);

        if ($meta === null) {
            return 168;
        }

        return $stored !== null && $stored >= $meta['min'] && $stored <= $meta['max'] ? $stored : $meta['default'];
    }

    /** Resets individuales por hora y operador sin contraseña (R3-1); 0 = siempre exige contraseña. */
    public function freeResetsPerHour(): int
    {
        $meta   = GlobalSettingsRegistry::find(GlobalSettingsRegistry::PASSWORD_RESET_FREE_PER_HOUR);
        $stored = $this->stored(GlobalSettingsRegistry::PASSWORD_RESET_FREE_PER_HOUR);

        if ($meta === null) {
            return 5;
        }

        return $stored !== null && $stored >= $meta['min'] && $stored <= $meta['max'] ? $stored : $meta['default'];
    }

    public function enabled(): bool
    {
        $stored = $this->stored(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED);

        return $stored !== null ? $stored === 1 : GlobalSettingsRegistry::defaultFor(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED) === 1;
    }

    /** Momento en que vence la contraseña temporal de un reset administrativo hecho en `$resetAt`. */
    public function expiresAt(CarbonInterface $resetAt): CarbonInterface
    {
        return $resetAt->copy()->addHours($this->expirationHours());
    }

    /**
     * Cuenta con un reset administrativo pendiente cuyo plazo ya venció. Solo aplica con `must_change_password` activo y
     * `password_reset_at` fijado: quien no tiene reset pendiente nunca "caduca".
     */
    public function isExpired(User $user): bool
    {
        return $this->isExpiredRow($user->must_change_password === true, $user->password_reset_at);
    }

    /** Versión sin modelo completo (AuthenticateJwt ya tiene la fila con solo estas columnas). */
    public function isExpiredRow(bool $mustChangePassword, ?CarbonInterface $resetAt): bool
    {
        if (! $mustChangePassword || $resetAt === null) {
            return false;
        }

        return $this->expiresAt($resetAt)->isPast();
    }

    /** Valor guardado (entero) del ajuste, o null si no existe o no es numérico. */
    private function stored(string $key): ?int
    {
        $raw = Cache::remember(GlobalSettingsRegistry::cacheKey($key), self::CACHE_SECONDS, static function () use ($key): string {
            $setting = GlobalSetting::find($key);

            return $setting === null || $setting->value === '' || ! is_numeric($setting->value) ? '' : (string) (int) $setting->value;
        });

        return $raw === '' ? null : (int) $raw;
    }
}
