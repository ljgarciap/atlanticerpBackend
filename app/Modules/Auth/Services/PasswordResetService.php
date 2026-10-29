<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Support\NoLoginAccounts;
use App\Modules\Auth\Mail\AccountActivationMail;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Models\PasswordResetToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class PasswordResetService
{
    public function __construct(private readonly PasswordNotifier $notifier, private readonly PasswordResetPolicy $policy) {}

    private const TTL_HOURS        = 1;
    private const TOKEN_BYTES      = 32;

    public function sendResetLink(string $email): void
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        // SCRUM-1142 — las cuentas sin acceso (Tecnico, Repartidor, Personal de Bodega) nunca reciben enlace: «olvidé mi contraseña»
        // les permitiría fijar una contraseña y entrar. Misma respuesta silenciosa que un correo inexistente (no se revela nada).
        if (NoLoginAccounts::isNoLogin($user->email)) {
            return;
        }

        $rawToken = $this->issueToken($email, self::TTL_HOURS);

        // SCRUM-1178 — el token viaja en el FRAGMENTO (#): el navegador nunca lo envía al servidor, así no queda en el access.log de nginx.
        $resetUrl = $this->frontendUrl("/reset-password#token={$rawToken}");

        // El usuario recibe siempre la misma respuesta, exista el correo o no: un fallo al encolar se registra (sin el token) y no
        // se convierte en un 500 que además revelaría que la cuenta existe.
        try {
            Mail::to($email)->send(new PasswordResetMail($user->first_name, $resetUrl, $this->notifier->locale($user)));
        } catch (\Throwable $e) {
            Log::error('Restablecimiento de contraseña: no se pudo encolar el correo', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    /**
     * Fase 12c — enlace de activación: una cuenta nueva o reiniciada NO tiene contraseña utilizable; la persona elige la suya con este
     * enlace (la misma página y el mismo endpoint de «restablecer», `reset()`). Vigencia = `password_reset_expiration_hours`. Devuelve el enlace
     * (para el comando de soporte) o `null` si la cuenta no puede activarse (inactiva o sin acceso). Un nuevo enlace anula los anteriores.
     */
    public function activationLink(User $user): ?string
    {
        if ($user->is_active !== true || NoLoginAccounts::isNoLogin($user->email)) {
            return null;
        }

        $rawToken = $this->issueToken($user->email, $this->policy->expirationHours());

        return $this->frontendUrl("/reset-password#token={$rawToken}");
    }

    /** Genera el enlace de activación y lo envía por correo (cola). Un fallo de envío se registra (sin el token) y no deshace el alta o el reinicio. */
    public function sendActivationLink(User $user): void
    {
        $url = $this->activationLink($user);
        if ($url === null) {
            return;
        }

        try {
            Mail::to($user->email)->send(new AccountActivationMail($user->first_name, $url, $this->policy->expirationHours(), $this->notifier->locale($user)));
        } catch (\Throwable $e) {
            Log::error('Activación de cuenta: no se pudo encolar el correo', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    public function reset(string $rawToken, string $newPassword): void
    {
        $tokenHash = $this->hash($rawToken);

        /** @var PasswordResetToken|null $record */
        $record = PasswordResetToken::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($record === null || $record->isExpired() || $record->isUsed()) {
            throw ValidationException::withMessages([
                'token' => ['El enlace es inválido o ha expirado. Solicita uno nuevo.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $record->email)->first();

        // SCRUM-1142 — defensa en profundidad: un token emitido ANTES de que `sendResetLink()` ignorara a las cuentas sin acceso
        // (Tecnico, Repartidor, Personal de Bodega) seguiría vigente y les daría una contraseña y acceso. Mismo error que un enlace inválido.
        if ($user === null || NoLoginAccounts::isNoLogin($user->email)) {
            throw ValidationException::withMessages([
                'token' => ['El enlace es inválido o ha expirado.'],
            ]);
        }

        // Mismo criterio que el cambio de contraseña propio: la contraseña no puede ser el correo (es la que deja un reset
        // administrativo, ver AdminPasswordResetService).
        if (mb_strtolower($newPassword) === mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'password' => [trans('auth.password_same_as_email')],
            ]);
        }

        $user->password_hash = $newPassword;
        // Si la cuenta tenía un reset administrativo pendiente, esta es la contraseña nueva elegida por su dueño.
        $user->must_change_password = false;
        $user->save();

        $record->update(['used_at' => now()]);

        $refreshTable = $user->getTable();
        $refreshTable = str_replace('users', 'refresh_tokens', $refreshTable);
        \Illuminate\Support\Facades\DB::table($refreshTable)
            ->where('user_id', $user->id)
            ->update(['revoked' => true]);

        // S2: aviso al dueño de la cuenta de que su contraseña cambió (un fallo de envío no rompe el cambio).
        $this->notifier->notifyChanged($user);
    }

    /** Invalida los tokens vivos del correo y crea uno nuevo; devuelve el valor en claro (solo viaja en el enlace). */
    private function issueToken(string $email, int $ttlHours): string
    {
        PasswordResetToken::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));

        PasswordResetToken::create([
            'email'      => $email,
            'token_hash' => $this->hash($rawToken),
            'expires_at' => now()->addHours($ttlHours),
        ]);

        return $rawToken;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function frontendUrl(string $path): string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        return $base . $path;
    }
}
