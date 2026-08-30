<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentRetentionAttachment;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Batch 19 (REQ-525) — constancia fiscal de retención, distinta del comprobante de pago
 * (`AdminContPaymentAttachmentService`, del cual este servicio es un clon deliberado — ver
 * docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md, reconciliación punto 6). Única
 * diferencia real: acá "subir" y "reemplazar" son el mismo caso de uso explícito del REQ, así que
 * `upload()` borra el objeto S3 anterior antes de subir el nuevo cuando ya existía una fila para
 * ese `payment_id` — el original nunca necesitó ese camino (a lo sumo 1 subida por cobro).
 */
final class AdminContPaymentRetentionAttachmentService
{
    private const DISK = 's3-private';

    /** Presigned URL lifetime (minutes) — mismo criterio que el comprobante de pago. */
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, AdminContPayment $payment, int $uploadedBy): AdminContPaymentRetentionAttachment
    {
        $existing = AdminContPaymentRetentionAttachment::query()->where('payment_id', $payment->id)->first();

        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/payments/{$payment->id}/retencion/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(
                dirname($key),
                $file,
                basename($key),
            );

            if ($uploaded === false) {
                throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
            }
        });

        try {
            $attachment = AdminContPaymentRetentionAttachment::updateOrCreate(
                ['payment_id' => $payment->id],
                [
                    'nombre_archivo' => $file->getClientOriginalName(),
                    'storage_key'    => $key,
                    'size_bytes'     => $file->getSize(),
                    'mime_type'      => $file->getMimeType() ?? 'application/octet-stream',
                    'uploaded_by'    => $uploadedBy,
                ],
            );
        } catch (\Throwable $e) {
            try {
                Storage::disk(self::DISK)->delete($key);
            } catch (\Throwable $se) {
                Log::error('admin_cont_payment_retention_attachment_service.storage_cleanup_failed', [
                    'disk'  => self::DISK,
                    'key'   => $key,
                    'error' => $se->getMessage(),
                ]);
            }

            throw $e;
        }

        // Reemplazo real (RN del REQ-525: "subir o reemplazar") — borra el objeto viejo DESPUÉS de
        // confirmar que la fila nueva quedó bien, para no perder la constancia previa si el
        // updateOrCreate() de arriba fallara.
        if ($existing !== null && $existing->storage_key !== $key) {
            try {
                Storage::disk(self::DISK)->delete($existing->storage_key);
            } catch (\Throwable $se) {
                Log::error('admin_cont_payment_retention_attachment_service.old_key_cleanup_failed', [
                    'disk'  => self::DISK,
                    'key'   => $existing->storage_key,
                    'error' => $se->getMessage(),
                ]);
            }
        }

        return $attachment;
    }

    /** URL firmada de 15 min para ver la constancia. */
    public function presignedUrl(AdminContPaymentRetentionAttachment $attachment): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $attachment->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }
}
