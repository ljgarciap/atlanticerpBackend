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
 * Fase 12c — enlace para elegir la contraseña de una cuenta nueva o reiniciada (reemplaza a la clave inicial = correo). Mismas garantías que
 * `PasswordResetMail`: cifrado en la cola (el enlace lleva el token en claro), reintentos con espera y el idioma del perfil.
 */
final class AccountActivationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
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
        public readonly string $activationUrl,
        public readonly int $validHours,
        public readonly string $lang = 'es',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('auth.mail.activation.subject', [], $this->lang));
    }

    public function content(): Content
    {
        $t = fn (string $key, array $replace = []): string => trans('auth.mail.activation.'.$key, $replace, $this->lang);

        // La vista de «restablecer» ya es genérica (etiqueta, saludo, texto, botón, enlace y aviso): se reutiliza.
        return new Content(view: 'emails.password-reset', with: [
            'locale' => $this->lang,
            'resetUrl' => $this->activationUrl,
            'tag' => $t('tag'),
            'greeting' => trans('auth.mail.greeting', ['name' => $this->firstName], $this->lang),
            'body' => $t('body'),
            'button' => $t('button'),
            'fallback' => $t('fallback'),
            'warning' => $t('warning', ['hours' => $this->validHours]),
            'footer' => trans('auth.mail.footer', [], $this->lang),
        ]);
    }
}
