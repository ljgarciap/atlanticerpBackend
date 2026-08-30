<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Mail\MfaCodeMail;
use App\Modules\Auth\Models\MfaCode;
use App\Modules\Auth\Models\SystemSetting;
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
     * Throws ValidationException on failure.
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

        return $mfa->user_id;
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
