<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Jobs\SendNotificationEmailJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSend;
use Carbon\Carbon;

/**
 * Genérico — no conoce Project, Activity, ni ningún concepto de CRM. Recibe una audiencia ya
 * resuelta (el caller decide quién: asignados de un proyecto, un rol, una lista manual) más
 * contenido y canal(es), y solo entrega + trackea. Ver docs/specs/notifications.md.
 */
final class NotificationService
{
    /**
     * @param list<int> $audience user ids destinatarios, ya resueltos por el caller
     * @param array{subject: string, body: string, cta_url?: string} $content
     * @param list<string> $channels 'in_app' | 'email'
     */
    public function send(
        array $audience,
        array $content,
        array $channels,
        ?Carbon $scheduledAt = null,
        string $source = NotificationSend::SOURCE_MANUAL_CIRCULAR,
        ?string $sourceRef = null,
        ?int $createdBy = null,
    ): NotificationSend {
        /** @var NotificationSend $send */
        $send = NotificationSend::create([
            'source'       => $source,
            'source_ref'   => $sourceRef,
            'content'      => $content,
            'audience'     => array_values(array_unique($audience)),
            'channels'     => array_values(array_unique($channels)),
            'scheduled_at' => $scheduledAt,
            'created_by'   => $createdBy,
        ]);

        if ($send->isDue()) {
            $this->deliver($send);
        }

        return $send;
    }

    /**
     * Entrega un send pendiente (llamado desde send() si es inmediato, o desde
     * DispatchScheduledSendsJob cuando llega su scheduled_at).
     */
    public function deliver(NotificationSend $send): void
    {
        if (! $send->isPending()) {
            return; // ya se procesó — protege contra doble disparo del scheduler
        }

        /** @var list<int> $audience */
        $audience = $send->audience;
        /** @var list<string> $channels */
        $channels = $send->channels;
        /** @var array{subject: string, body: string, cta_url?: string} $content */
        $content = $send->content;

        foreach ($audience as $userId) {
            if (in_array(NotificationSend::CHANNEL_IN_APP, $channels, true)) {
                Notification::create([
                    'user_id'              => $userId,
                    'notification_send_id' => $send->id,
                    'content'              => $content,
                    'link_url'             => $content['cta_url'] ?? null,
                ]);
            }

            if (in_array(NotificationSend::CHANNEL_EMAIL, $channels, true)) {
                SendNotificationEmailJob::dispatch(
                    $send->id,
                    $userId,
                    $content['subject'],
                    $content['body'],
                    $content['cta_url'] ?? null,
                );
            }
        }

        $send->update(['sent_at' => now()]);
    }
}
