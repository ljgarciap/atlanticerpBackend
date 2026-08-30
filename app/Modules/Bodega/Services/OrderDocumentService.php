<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Shared\Support\CircuitBreaker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-330) — Guía de Entrega: documento generado server-side con
 * dompdf, mismo mecanismo que `App\Modules\Compras\Services\PurchaseOrderPdfService`. Factura
 * (REQ-331) NO se implementa en este batch (depende de un módulo de Administración & Contabilidad
 * que todavía no existe). Estatus de Pedido (REQ-341) se agrega en Batch A4, ver
 * `generateEstatusPedido()` — mismo mecanismo que `generateGuiaEntrega()`.
 */
final class OrderDocumentService
{
    private const DISK = 's3-private';

    public function generateGuiaEntrega(Order $order, \App\Models\User $actor): OrderDocument
    {
        // Lote 4 (SCRUM-395, 2026-08-17) — `pipelineCard.contacts` agregado para exponer
        // teléfono/correo sueltos en el documento rediseñado (ver `guia-entrega-pdf.blade.php`),
        // mismo criterio que `OrderBoardService::detail()` para el modal "Ver detalle".
        $order->loadMissing([
            'items.catalogProduct', 'assistant', 'picker', 'courier',
            'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject',
        ]);

        // SCRUM-395 (rebote 2026-08-18) — `isPhpEnabled` necesario para que el pie de página use
        // `page_text()` (ver `<script type="text/php">` en la vista) en vez de un div
        // `position:fixed; bottom:...`: ese segundo mecanismo es un bug conocido de dompdf (genera
        // páginas en blanco adicionales) — el único mecanismo realmente validado en este código
        // para repetir contenido al pie en cada página es `page_text()`, mismo patrón que
        // `QuotePdfService` (SCRUM-766).
        $pdf = Pdf::setOption('isPhpEnabled', true)
            ->loadView('bodega.guia-entrega-pdf', ['order' => $order])
            ->setPaper('letter');
        $key = "bodega/orders/{$order->id}/guia-entrega-".now()->format('YmdHis').'-'.Str::uuid()->toString().'.pdf';

        (new CircuitBreaker(self::DISK))->call(function () use ($key, $pdf): void {
            $ok = Storage::disk(self::DISK)->put($key, $pdf->output());
            if ($ok === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $order->documents()->create([
            'tipo'        => OrderDocument::TIPO_GUIA_ENTREGA,
            'storage_key' => $key,
            'uploaded_by' => $actor->id,
            'created_at'  => now(),
        ]);
    }

    /**
     * SCRUM-329 Oleada A / Batch A4 (REQ-341) — documento "Estatus de Pedido": mismo mecanismo que
     * `generateGuiaEntrega()` (dompdf + S3 + fila `OrderDocument` append-only), pero SIEMPRE sobre
     * la tarjeta RAÍZ de la familia (`$order` ya viene resuelta por
     * `OrderStatusService::resolveRoot()`) y con la paleta verde institucional — RN1 explícito:
     * el ejemplo de negocio que trajo el cliente estaba en rojo, decisión fue NO replicar ese
     * color, usar el verde `#5BA5A0` de siempre (ver `resources/views/bodega/estatus-pedido-pdf.blade.php`).
     *
     * `$statusPayload` es EXACTAMENTE el array de `OrderStatusService::detailPayload()` — una sola
     * fuente de datos para el modal "Ver detalle" (JSON) y este documento (PDF), nunca se
     * recalcula la consolidación de familia acá (REQ-341 RN1: "los mismos datos que el modal de
     * detalle").
     *
     * @param array<string, mixed> $statusPayload
     */
    public function generateEstatusPedido(Order $order, array $statusPayload, \App\Models\User $actor): OrderDocument
    {
        $pdf = Pdf::loadView('bodega.estatus-pedido-pdf', ['status' => $statusPayload])->setPaper('letter');
        $key = "bodega/orders/{$order->id}/estatus-pedido-".now()->format('YmdHis').'-'.Str::uuid()->toString().'.pdf';

        (new CircuitBreaker(self::DISK))->call(function () use ($key, $pdf): void {
            $ok = Storage::disk(self::DISK)->put($key, $pdf->output());
            if ($ok === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $order->documents()->create([
            'tipo'        => OrderDocument::TIPO_ESTATUS_PEDIDO,
            'storage_key' => $key,
            'uploaded_by' => $actor->id,
            'created_at'  => now(),
        ]);
    }

    /** REQ-329 — evidencia física (foto/escaneo) de la guía firmada por el cliente. */
    public function uploadSignedGuide(Order $order, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "bodega/orders/{$order->id}/guia-firmada-".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($order, $file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs("bodega/orders/{$order->id}", $file, basename($key));
            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        return $key;
    }

    public function presignUrl(string $storageKey): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($storageKey, now()->addMinutes(15));
    }
}
