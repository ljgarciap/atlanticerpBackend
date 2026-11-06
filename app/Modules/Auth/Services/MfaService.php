<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\MfaThrottledException;
use App\Modules\Auth\Mail\MfaCodeMail;
use App\Modules\Auth\Models\MfaBackupCode;
use App\Modules\Auth\Models\MfaCode;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Auth\Support\AuthThrottle;
use App\Shared\Auth\Totp;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MfaService
{
    private const TTL_MINUTES = 10;
    private const CODE_DIGITS = 6;
    public const BACKUP_CODES = 10;
    public const METHOD_EMAIL = 'email';
    public const METHOD_TOTP  = 'totp';
    /** Sin 0/O/1/I/L: un código de respaldo se copia a mano de un papel. */
    private const BACKUP_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

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

    /** Fase 27c — persona con aplicación autenticadora activa: el reto solo ata el paso a una sesión (no hay correo ni código que enviar). */
    public function usesTotp(User $user): bool
    {
        return $user->mfa_method === self::METHOD_TOTP && $user->mfa_totp_secret !== null;
    }

    public function generateTotpChallenge(User $user): string
    {
        $challengeId = (string) Str::uuid();

        MfaCode::create([
            'id'         => $challengeId,
            'user_id'    => $user->id,
            'method'     => self::METHOD_TOTP,
            // Valor al azar que nadie conoce: este reto nunca se resuelve por la vía del correo.
            'code_hash'  => $this->hash(Str::random(40)),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $challengeId;
    }

    /** Método del reto (`email` si no existe, para que `resend` y `verify` respondan igual que antes). */
    public function methodForChallenge(string $challengeId): ?string
    {
        /** @var MfaCode|null $mfa */
        $mfa = MfaCode::find($challengeId);

        return $mfa?->method;
    }

    /**
     * Validates the code for a challenge.
     * Returns the user_id on success.
     * Throws ValidationException on failure, MfaThrottledException when the per-user cap is exhausted (HTTP 429).
     */
    public function verify(string $challengeId, string $code, string $scope = 'verify'): int
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
        // `$scope` separa el tope de la ACTIVACIÓN y de la DESACTIVACIÓN del tope de ingreso (Fase 27a): fallar al ingresar no impide activar.
        $userKey = self::userThrottleKey($mfa->user_id, $scope);
        // El intento se RESERVA antes de comparar el código (incremento atómico: una ráfaga paralela no multiplica los intentos);
        // un código correcto lo libera más abajo.
        if (($retry = AuthThrottle::reservar([$userKey => [(int) $cfg['user_max'], (int) $cfg['user_decay']]], AuthThrottle::CERRAR)) !== null) {
            throw new MfaThrottledException($retry);
        }

        $valid = $mfa->method === self::METHOD_TOTP
            ? $this->checkSecondFactor(User::find($mfa->user_id), $code)
            : hash_equals($mfa->code_hash, $this->hash($code));

        if (! $valid) {
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

        if ($mfa->method === self::METHOD_TOTP) {
            throw ValidationException::withMessages([
                'mfa_challenge_id' => ['Con la aplicación autenticadora no hay código que reenviar.'],
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
        $this->clearTotp($user);
        $user->save();
    }

    /** Reinicio por un administrador: apaga el MFA, borra secreto y respaldos y limpia los topes de errores de la persona (Fase 27a a3; 27c). */
    public function resetForUser(User $user): void
    {
        $this->disableForUser($user);

        foreach (['verify', 'enable', 'disable', 'regenerate'] as $scope) {
            AuthThrottle::limpiar(self::userThrottleKey($user->id, $scope));
        }
    }

    // ── Fase 27c — aplicación autenticadora (TOTP) y códigos de respaldo ─────────

    /** Alta, paso 1: guarda un secreto PENDIENTE (cifrado) y devuelve los datos para dibujar el QR. El método no cambia hasta confirmar. */
    public function startTotpSetup(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->mfa_totp_pending_secret = $secret;
        $user->save();

        return $secret;
    }

    /**
     * Alta, paso 2: con un código válido del secreto pendiente se activa el método y se generan los respaldos.
     *
     * @return list<string>|null los 10 códigos de respaldo en claro (se muestran UNA vez), o null si el código no es válido
     * @throws MfaThrottledException
     */
    public function confirmTotpSetup(User $user, string $code): ?array
    {
        $pending = $user->mfa_totp_pending_secret;
        if ($pending === null) {
            return null;
        }

        // Mismo tope por usuario que la activación por correo: sin él, 6 dígitos se podrían forzar contra el secreto pendiente.
        $cfg     = (array) config('throttle.mfa_verify');
        $userKey = self::userThrottleKey($user->id, 'enable');
        if (($retry = AuthThrottle::reservar([$userKey => [(int) $cfg['user_max'], (int) $cfg['user_decay']]], AuthThrottle::CERRAR)) !== null) {
            throw new MfaThrottledException($retry);
        }
        $step = Totp::matchStep($pending, $code);
        if ($step === null) {
            return null;
        }
        AuthThrottle::limpiar($userKey);

        $user->mfa_method              = self::METHOD_TOTP;
        $user->mfa_totp_secret         = $pending;
        $user->mfa_totp_pending_secret = null;
        $user->mfa_totp_last_step      = $step;
        $user->mfa_enabled             = true;
        $user->save();

        return $this->regenerateBackupCodes($user);
    }

    /**
     * Genera 10 respaldos nuevos e invalida los anteriores.
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(User $user): array
    {
        // En una transacción: un fallo a medias no puede dejar a la persona sin ningún respaldo vigente.
        return DB::transaction(function () use ($user): array {
            MfaBackupCode::query()->where('user_id', $user->id)->delete();

            $codes = [];
            for ($i = 0; $i < self::BACKUP_CODES; $i++) {
                $plain = '';
                for ($j = 0; $j < 10; $j++) {
                    $plain .= self::BACKUP_ALPHABET[random_int(0, strlen(self::BACKUP_ALPHABET) - 1)];
                }
                MfaBackupCode::create(['user_id' => $user->id, 'code_hash' => $this->backupHash($plain)]);
                $codes[] = substr($plain, 0, 5) . '-' . substr($plain, 5);
            }

            return $codes;
        });
    }

    public function backupCodesRemaining(User $user): int
    {
        return MfaBackupCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    /**
     * Segundo factor de una persona con aplicación autenticadora: el código de la aplicación (cada paso de 30 s sirve UNA vez) o un
     * código de respaldo (sirve una vez). Los dos consumos son atómicos: dos peticiones en paralelo con el mismo código no pasan ambas.
     */
    public function checkSecondFactor(?User $user, string $code): bool
    {
        if ($user === null || ! $this->usesTotp($user)) {
            return false;
        }

        $clean = strtoupper(preg_replace('/[\s-]/', '', $code) ?? '');

        if (preg_match('/^\d{6}$/', $clean) === 1) {
            $step = Totp::matchStep((string) $user->mfa_totp_secret, $clean);

            // Un paso igual o anterior al último aceptado es una repetición (o un código viejo): no cuenta.
            return $step !== null && User::query()->whereKey($user->id)
                ->where(static fn ($q) => $q->whereNull('mfa_totp_last_step')->orWhere('mfa_totp_last_step', '<', $step))
                ->update(['mfa_totp_last_step' => $step]) === 1;
        }

        if (preg_match('/^[A-Z0-9]{10}$/', $clean) === 1) {
            $used = MfaBackupCode::query()->where('user_id', $user->id)->where('code_hash', $this->backupHash($clean))
                ->whereNull('used_at')->update(['used_at' => now()]) === 1;
            if ($used) {
                app(AuditService::class)->log(
                    action:       AuditService::SECURITY_MFA_BACKUP_USED,
                    entityType:   'user',
                    entityId:     $user->id,
                    actingUserId: $user->id,
                    metadata:     ['remaining' => $this->backupCodesRemaining($user)],
                );
            }

            return $used;
        }

        return false;
    }

    /**
     * Prueba de «soy yo» para las acciones sensibles (cambiar de método, desactivar, regenerar respaldos). Con aplicación autenticadora
     * basta el código (o un respaldo); con correo hace falta el reto enviado antes. Comparte el tope por usuario de `$scope`.
     *
     * @throws ValidationException|MfaThrottledException
     */
    public function verifyStepUp(User $user, ?string $challengeId, string $code, string $scope): void
    {
        if ($this->usesTotp($user)) {
            $cfg     = (array) config('throttle.mfa_verify');
            $userKey = self::userThrottleKey($user->id, $scope);
            if (($retry = AuthThrottle::reservar([$userKey => [(int) $cfg['user_max'], (int) $cfg['user_decay']]], AuthThrottle::CERRAR)) !== null) {
                throw new MfaThrottledException($retry);
            }
            if (! $this->checkSecondFactor($user, $code)) {
                throw ValidationException::withMessages(['code' => ['Código incorrecto.']]);
            }
            AuthThrottle::limpiar($userKey);

            return;
        }

        if ($challengeId === null) {
            throw ValidationException::withMessages(['mfa_challenge_id' => ['Falta la verificación enviada a tu correo.']]);
        }
        $this->verify($challengeId, $code, $scope);
    }

    private function clearTotp(User $user): void
    {
        $user->mfa_method              = self::METHOD_EMAIL;
        $user->mfa_totp_secret         = null;
        $user->mfa_totp_pending_secret = null;
        $user->mfa_totp_last_step      = null;
        MfaBackupCode::query()->where('user_id', $user->id)->delete();
    }

    private function backupHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private static function userThrottleKey(int $userId, string $scope): string
    {
        return $scope === 'verify' ? 'mfa:verify:user:' . $userId : 'mfa:' . $scope . ':user:' . $userId;
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
