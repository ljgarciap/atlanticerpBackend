<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de seguridad al dueño de la cuenta cuando su contraseña cambia (reset administrativo o cambio propio/olvidé mi contraseña).
 * Un solo Mailable con dos tipos (`admin_reset`, `changed`); los textos viven en `lang/{es,en}/auth.php` (`auth.mail.*`).
 * NUNCA incluye contraseñas ni enlaces con credenciales (por eso es seguro serializarlo en la cola: `ShouldQueue`, R3-7).
 */
final class PasswordNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const TYPE_ADMIN_RESET = 'admin_reset';
    public const TYPE_CHANGED     = 'changed';

    /**
     * @param string $type   self::TYPE_*
     * @param string $lang   'es' | 'en' (no se llama `locale` porque Mailable ya tiene esa propiedad)
     * @param string $date   Fecha ya formateada: vencimiento (admin_reset) o momento del cambio (changed)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $firstName,
        public readonly string $lang,
        public readonly string $date,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('auth.mail.' . $this->type . '.subject', [], $this->lang));
    }

    public function content(): Content
    {
        $t = fn (string $key, array $replace = []): string => trans('auth.mail.' . $this->type . '.' . $key, $replace, $this->lang);

        $paragraphs = [$t('body', ['date' => $this->date])];
        if ($this->type === self::TYPE_ADMIN_RESET) {
            $paragraphs[] = $t('expires', ['date' => $this->date]);
        }

        return new Content(view: 'emails.password-notice', with: [
            'locale'     => $this->lang,
            'tag'        => $t('tag'),
            'greeting'   => trans('auth.mail.greeting', ['name' => $this->firstName], $this->lang),
            'paragraphs' => $paragraphs,
            'notMe'      => $t('notme'),
            'footer'     => trans('auth.mail.footer', [], $this->lang),
        ]);
    }
}
