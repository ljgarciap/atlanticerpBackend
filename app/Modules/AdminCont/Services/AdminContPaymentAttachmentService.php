<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentAttachment;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-468 — comprobante de pago (imagen o PDF), opcional, adjuntado al registrar un cobro. Siempre
 * privado (disco `s3-private`), sin versionado ni límite de cantidad (RN2/RN3 — a lo sumo 1 por
 * cobro) — mismo patrón que `Servicios\Services\TicketAttachmentService`, simplificado (sin
 * `lockForUpdate`/límite de cantidad, que no aplican con un único adjunto opcional).
 */
final class AdminContPaymentAttachmentService
{
    private const DISK = 's3-private';

    /** Presigned URL lifetime (minutes) — REQ-472, mismo criterio que TicketAttachmentService. */
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, AdminContPayment $payment, int $uploadedBy): AdminContPaymentAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/payments/{$payment->id}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

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
            return AdminContPaymentAttachment::create([
                'payment_id'     => $payment->id,
                'nombre_archivo' => $file->getClientOriginalName(),
                'storage_key'    => $key,
                'size_bytes'     => $file->getSize(),
                'mime_type'      => $file->getMimeType() ?? 'application/octet-stream',
                'uploaded_by'    => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            try {
                Storage::disk(self::DISK)->delete($key);
            } catch (\Throwable $se) {
                Log::error('admin_cont_payment_attachment_service.storage_cleanup_failed', [
                    'disk'  => self::DISK,
                    'key'   => $key,
                    'error' => $se->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /** REQ-472 — URL firmada de 15 min para ver el comprobante. */
    public function presignedUrl(AdminContPaymentAttachment $attachment): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $attachment->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }
}
