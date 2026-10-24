<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Mail\PasswordNoticeMail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos por correo al dueño de la cuenta cuando su contraseña cambia (S2 del cierre de H2/H3/H4): permite detectar rápido una
 * toma de cuenta. Los avisos van a COLA (`Mail::queue`, R3-7): el reset masivo no depende del SMTP síncrono ni queda a medias si
 * el servidor de correo tarda o falla (el correo no lleva contraseñas, así que serializarlo es seguro). Un fallo al encolar o al
 * enviar NUNCA rompe la operación que lo originó (se registra sin datos sensibles y se sigue).
 * En dev/test el mailer es Mailpit: no llegan a personas reales.
 */
final class PasswordNotifier
{
    /** Reset administrativo: avisa que debe cambiar la contraseña antes de `$expiresAt`. */
    public function notifyAdminReset(User $user, CarbonInterface $expiresAt): void
    {
        $this->send($user, PasswordNoticeMail::TYPE_ADMIN_RESET, $expiresAt);
    }

    /** La contraseña fue cambiada (cambio propio u olvidé mi contraseña). */
    public function notifyChanged(User $user): void
    {
        $this->send($user, PasswordNoticeMail::TYPE_CHANGED, now());
    }

    private function send(User $user, string $type, CarbonInterface $date): void
    {
        try {
            $locale = $this->locale($user);
            $when   = $date->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i');

            Mail::to($user->email)->queue(new PasswordNoticeMail($type, (string) $user->first_name, $locale, $when));
        } catch (\Throwable $e) {
            // Solo ids y la clase del error: nunca el correo, el mensaje con datos ni nada de la contraseña.
            Log::warning('No se pudo enviar el aviso de contraseña', ['user_id' => $user->id, 'type' => $type, 'error' => $e::class]);
        }
    }

    /** Idioma (es/en) de los correos de seguridad de esta persona, según su perfil. */
    public function locale(User $user): string
    {
        $prefs = is_array($user->preferences) ? $user->preferences : [];
        $lang  = $prefs['language'] ?? config('app.locale', 'es');

        return in_array($lang, ['es', 'en'], true) ? $lang : 'es';
    }
}
