<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\MfaThrottledException;
use App\Modules\Auth\Mail\MfaCodeMail;
use App\Modules\Auth\Models\MfaCode;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Auth\Support\AuthThrottle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MfaService
{
    private const TTL_MINUTES = 10;
    private const CODE_DIGITS = 6;

    public function isRequired(User $user): bool
    {
        $mode = $this->mfaMode();

        // 'disabled' solo impide que la organización lo fuerce y oculta el auto-servicio de
        // activación (ver EditProfileDrawer/SettingsPage) — no debe apagar el MFA de un usuario
        // que ya lo activó personalmente, o un cambio de política produce un downgrade de
        // seguridad silencioso (ver SCRUM-2, reporte de QA 2026-07-05).
        return match ($mode) {
            'required' => true,
            default    => (bool) $user->mfa_enabled,
        };
    }

    /** @throws MfaDeliveryException si el correo con el código no pudo enviarse */
    public function generateChallenge(User $user): string
    {
        $code        = $this->randomCode();
        $challengeId = (string) Str::uuid();

        $mfaCode = MfaCode::create([
            'id'        => $challengeId,
            'user_id'   => $user->id,
            'code_hash' => $this->hash($code),
            'attempts'  => 0,
            'expires_at'=> now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            Mail::to($user->email)->send(new MfaCodeMail($user->first_name, $code));
        } catch (\Throwable $e) {
            $mfaCode->delete();
            Log::error('MFA challenge email delivery failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            throw new MfaDeliveryException('No se pudo enviar el código de verificación.', previous: $e);
        }

        return $challengeId;
    }

    /**
     * Validates the code for a challenge.
     * Returns the user_id on success.
     * Throws ValidationException on failure, MfaThrottledException when the per-user cap is exhausted (HTTP 429).
     */
    public function verify(string $challengeId, string $code): int
    {
        /** @var MfaCode|null $mfa */
        $mfa = MfaCode::find($challengeId);

        if ($mfa === null || ! $mfa->isValid()) {
            throw ValidationException::withMessages([
                'code' => ['El código es inválido o ha expirado.'],
            ]);
        }

        // H4: tope de códigos erróneos por USUARIO en la ventana (config/throttle.php `mfa_verify.user_*`). A diferencia de `attempts`
        // (por reto), este contador NO se reinicia al reenviar el código: sin él, cada reenvío daba 5 intentos nuevos y un código de
        // 6 dígitos se podía forzar. Se libera solo al vencer la ventana o con un código correcto.
        $cfg     = (array) config('throttle.mfa_verify');
        $userKey = 'mfa:verify:user:' . $mfa->user_id;
        // El intento se RESERVA antes de comparar el código (incremento atómico: una ráfaga paralela no multiplica los intentos);
        // un código correcto lo libera más abajo.
        if (($retry = AuthThrottle::reservar([$userKey => [(int) $cfg['user_max'], (int) $cfg['user_decay']]], AuthThrottle::CERRAR)) !== null) {
            throw new MfaThrottledException($retry);
        }

        if (! hash_equals($mfa->code_hash, $this->hash($code))) {
            $mfa->increment('attempts');

            if ($mfa->fresh()?->isExhausted() === true) {
                throw ValidationException::withMessages([
                    'code' => ['Demasiados intentos fallidos. Solicita un nuevo código.'],
                ]);
            }

            throw ValidationException::withMessages([
                'code' => ['Código incorrecto.'],
            ]);
        }

        $mfa->update(['used_at' => now()]);
        AuthThrottle::limpiar($userKey);

        return $mfa->user_id;
    }

    /** Dueño de un reto, sin exigir que siga vigente (lo usa el límite de reenvíos). */
    public function userIdForChallenge(string $challengeId): ?int
    {
        /** @var MfaCode|null $mfa */
        $mfa = MfaCode::find($challengeId);

        return $mfa?->user_id;
    }

    /**
     * Invalidates the current challenge and issues a fresh one for the same user.
     */
    public function resend(string $challengeId): string
    {
        /** @var MfaCode|null $mfa */
        $mfa = MfaCode::find($challengeId);

        if ($mfa === null) {
            throw ValidationException::withMessages([
                'mfa_challenge_id' => ['Sesión MFA no encontrada.'],
            ]);
        }

        $mfa->update(['used_at' => now()]);

        $user = User::findOrFail($mfa->user_id);

        return $this->generateChallenge($user);
    }

    public function getUserIdFromChallenge(string $challengeId): ?int
    {
        /** @var MfaCode|null $mfa */
        $mfa = MfaCode::find($challengeId);

        return ($mfa !== null && $mfa->isValid()) ? $mfa->user_id : null;
    }

    public function enableForUser(User $user): void
    {
        $user->mfa_enabled = true;
        $user->save();
    }

    public function disableForUser(User $user): void
    {
        $user->mfa_enabled = false;
        $user->save();
    }

    public function getMfaMode(): string
    {
        return $this->mfaMode();
    }

    private function mfaMode(): string
    {
        /** @var SystemSetting|null $setting */
        $setting = SystemSetting::find('mfa_mode');
        return is_string($setting?->value) ? $setting->value : 'optional';
    }

    private function randomCode(): string
    {
        return str_pad((string) random_int(0, (int) str_repeat('9', self::CODE_DIGITS)), self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
