<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\TestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class MailTestCommand extends Command
{
    protected $signature   = 'mail:test {to : Recipient email address}';
    protected $description = 'Send a test email to verify SMTP configuration';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        $this->info("Mailer:  " . config('mail.default'));
        $this->info("Host:    " . config('mail.mailers.smtp.host', 'N/A'));
        $this->info("Port:    " . config('mail.mailers.smtp.port', 'N/A'));
        $this->info("From:    " . config('mail.from.address'));
        $this->info("To:      {$to}");
        $this->newLine();

        try {
            Mail::to($to)->send(new TestMail());
            $this->info('✅ Email sent successfully.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
