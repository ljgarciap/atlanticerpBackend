<?php

declare(strict_types=1);

namespace App\Modules\Compras\Jobs;

use App\Modules\Compras\Mail\PurchaseOrderMail;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Services\PurchaseOrderPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * REQ-136 — envío por correo al proveedor. Mismo patrón de resiliencia que
 * SendNotificationEmailJob (retry+backoff, ver tabla de "Patrones de resiliencia" en
 * atlanticerp/CLAUDE.md) — no se envía síncrono desde el controller.
 */
final class SendPurchaseOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $orderId,
        private readonly bool $includeCost,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(PurchaseOrderPdfService $pdfService): void
    {
        $order = PurchaseOrder::with('provider')->find($this->orderId);
        if ($order === null || $order->provider === null || $order->provider->email === null) {
            return;
        }

        $pdfContent = $pdfService->generate($order, $this->includeCost)->output();

        Mail::to($order->provider->email)->send(
            new PurchaseOrderMail($order->id, $order->provider->name, $pdfContent),
        );
    }
}
