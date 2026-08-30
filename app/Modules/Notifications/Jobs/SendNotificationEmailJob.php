<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Models\User;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Envía el canal email de un NotificationSend a un único destinatario. Despachado desde
 * dentro de un contexto con tenant ya activo (NotificationService::send()) — el paquete
 * de multi-tenancy inyecta el tenant en el payload automáticamente al momento del dispatch,
 * no hace falta resolverlo manualmente (a diferencia de jobs despachados desde un Command
 * que itera tenants, ver SecurityAnalyzeCommand).
 */
final class SendNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $notificationSendId,
        private readonly int $userId,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?string $ctaUrl,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        Mail::to($user->email)->send(new NotificationMail($this->subject, $this->body, $this->ctaUrl));

        NotificationDelivery::create([
            'notification_send_id' => $this->notificationSendId,
            'user_id'               => $this->userId,
            'channel'               => 'email',
            'status'                => NotificationDelivery::STATUS_SENT,
        ]);
    }

    /**
     * Se llama una sola vez, cuando se agotan los reintentos (backoff) — no en cada intento
     * fallido, para no dejar varias filas "failed" duplicadas por el mismo envío.
     */
    public function failed(\Throwable $exception): void
    {
        NotificationDelivery::create([
            'notification_send_id' => $this->notificationSendId,
            'user_id'               => $this->userId,
            'channel'               => 'email',
            'status'                => NotificationDelivery::STATUS_FAILED,
            'error'                 => $exception->getMessage(),
        ]);
    }
}
