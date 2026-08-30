<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: '[AtlanticERP] SMTP Connection Test');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.test');
    }
}
