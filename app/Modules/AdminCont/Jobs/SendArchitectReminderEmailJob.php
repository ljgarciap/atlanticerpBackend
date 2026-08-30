<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Jobs;

use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\VentasDiseno\Models\Architect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Batch 17 (SCRUM-595, REQ-518) — recordatorio al arquitecto para que entregue su cuenta de cobro.
 * Mismo patrón de resiliencia que SendPurchaseOrderEmailJob/SendNotificationEmailJob (retry+backoff,
 * ver tabla de "Patrones de resiliencia" en atlanticerp/CLAUDE.md) — nunca síncrono desde el controller.
 * Reusa `NotificationMail` (genérico, ya usado por el módulo Notifications) en vez de un Mailable
 * propio — no hay nada específico de este correo que justifique una plantilla nueva.
 */
final class SendArchitectReminderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $architectId,
        private readonly string $numeroPedido,
        private readonly string $cliente,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        $architect = Architect::find($this->architectId);
        if ($architect === null || $architect->email === null) {
            return;
        }

        Mail::to($architect->email)->send(new NotificationMail(
            'Atlantic — recordatorio de cuenta de cobro pendiente',
            "Hola {$architect->name},\n\n".
            "Te escribimos para recordarte que el pago de tu comisión por el proyecto \"{$this->cliente}\" ".
            "(pedido {$this->numeroPedido}) está pendiente de tu cuenta de cobro.\n\n".
            "Apenas nos la envíes, procedemos con el pago.\n\nGracias.",
        ));
    }
}
