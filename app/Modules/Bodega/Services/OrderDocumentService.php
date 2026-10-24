<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\PdfLogo;
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
        //
        // SCRUM-894 (rebote Daniela, 2026-09-13) — `items` se recarga con `load()` (SIEMPRE
        // fresco desde BD), nunca `loadMissing()`: `DeliveryService::registerDelivery()` llama a
        // este método DESPUÉS de actualizar `qty_delivered` de cada item vía instancias de
        // Eloquent separadas (`$locked->items()->lockForUpdate()->findOrFail(...)`), que nunca
        // tocan la colección `items` ya cacheada en `$locked` desde antes del loop —
        // `loadMissing()` no vuelve a consultar una relación que Eloquent ya considera "cargada",
        // así que el PDF se generaba con los `qty_delivered` viejos (típicamente todos en 0,
        // pedido recién llegado de Packing) → guía descargada sin ningún producto. El resto de
        // relaciones no se mutan en este flujo, así que quedan en `loadMissing()`.
        $order->load(['items.catalogProduct']);
        $order->loadMissing([
            'assistant', 'picker', 'courier',
            'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject',
        ]);

        // SCRUM-395 (rebote 2026-08-18) — `isPhpEnabled` necesario para que el pie de página use
        // `page_text()` (ver `<script type="text/php">` en la vista) en vez de un div
        // `position:fixed; bottom:...`: ese segundo mecanismo es un bug conocido de dompdf (genera
        // páginas en blanco adicionales) — el único mecanismo realmente validado en este código
        // para repetir contenido al pie en cada página es `page_text()`, mismo patrón que
        // `QuotePdfService` (SCRUM-766).
        $pdf = Pdf::setOption('isPhpEnabled', true)
            ->loadView('bodega.guia-entrega-pdf', ['order' => $order, 'logoDataUri' => PdfLogo::dataUri()])
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
        // La huella del contenido va en el nombre del objeto (sin migración): permite reconocer después un documento con el MISMO contenido.
        $key = "bodega/orders/{$order->id}/estatus-pedido-".self::estatusFingerprint($statusPayload).'-'.now()->format('YmdHis').'-'.Str::uuid()->toString().'.pdf';

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

    /**
     * SCRUM-1171 — reutiliza el documento «Estatus de Pedido» ya generado cuando el contenido NO cambió, y genera uno nuevo solo si cambió. «Mismo
     * contenido» = misma huella del payload (el mismo array que alimenta el PDF, incluida `fecha_estatus`: como mucho un documento nuevo por día
     * aunque nada más cambie, y nunca se sirve un PDF con una fecha o cantidades viejas). Se reutiliza solo si el objeto sigue existiendo en S3.
     *
     * @param array<string, mixed> $statusPayload
     */
    public function findOrGenerateEstatusPedido(Order $order, array $statusPayload, \App\Models\User $actor): OrderDocument
    {
        $needle = "bodega/orders/{$order->id}/estatus-pedido-".self::estatusFingerprint($statusPayload).'-';

        $existing = $order->documents()
            ->where('tipo', OrderDocument::TIPO_ESTATUS_PEDIDO)
            ->where('storage_key', 'like', addcslashes($needle, '%_\\').'%')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null && Storage::disk(self::DISK)->exists($existing->storage_key)) {
            return $existing;
        }

        return $this->generateEstatusPedido($order, $statusPayload, $actor);
    }

    /** @param array<string, mixed> $statusPayload */
    private static function estatusFingerprint(array $statusPayload): string
    {
        return substr(hash('sha256', (string) json_encode($statusPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
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
