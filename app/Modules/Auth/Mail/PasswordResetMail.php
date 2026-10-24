<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El idioma (es/en) es el del perfil de la persona, igual que el aviso de contraseña cambiada (PasswordNoticeMail).
 * ShouldBeEncrypted: el enlace lleva el token en claro; sin cifrar, el payload quedaría legible en Redis, en el panel de Horizon
 * y en `failed_jobs` mientras el token siga vigente (revisión de seguridad SCRUM-1178, hallazgo M1).
 * SCRUM-1178 — va a la cola (Horizon) con reintentos: una caída del proveedor de correo ya no tumba la petición ni pierde el aviso.
 */
final class PasswordResetMail extends Mailable implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly string $firstName,
        public readonly string $resetUrl,
        public readonly string $lang = 'es',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('auth.mail.reset.subject', [], $this->lang));
    }

    public function content(): Content
    {
        $t = fn (string $key): string => trans('auth.mail.reset.' . $key, [], $this->lang);

        return new Content(view: 'emails.password-reset', with: [
            'locale'   => $this->lang,
            'tag'      => $t('tag'),
            'greeting' => trans('auth.mail.greeting', ['name' => $this->firstName], $this->lang),
            'body'     => $t('body'),
            'button'   => $t('button'),
            'fallback' => $t('fallback'),
            'warning'  => $t('warning'),
            'footer'   => trans('auth.mail.footer', [], $this->lang),
        ]);
    }
}
