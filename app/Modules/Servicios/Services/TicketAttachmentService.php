<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Exceptions\TicketAttachmentLimitExceededException;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketAttachment;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fase 4 — Servicios, Batch 4 (REQ-248) — fotos/videos/archivos de referencia de un ticket.
 * Siempre privado (disco `s3-private`), sin versionado — mismo patrón general que
 * `CRM\Services\DocumentService::upload()`, simplificado (sin categoria/zone/versionado, que no
 * aplican acá).
 */
final class TicketAttachmentService
{
    private const DISK = 's3-private';

    /** Presigned URL lifetime (minutes) — mismo criterio que CRM\Services\DocumentService. */
    private const PRESIGNED_URL_TTL = 15;

    /**
     * @throws TicketAttachmentLimitExceededException RN2 — límite ya alcanzado. El conteo se
     *     re-verifica DENTRO de la transacción, con el ticket bloqueado (`lockForUpdate`), para
     *     que 2 subidas concurrentes sobre el mismo ticket no puedan pasar ambas el chequeo antes
     *     de que cualquiera de las dos inserte (mismo tipo de condición de carrera que Pre-QA ya
     *     encontró antes en Bodega/Compras — ver memory/feedback_preqa_race_condition_evidence_20260805.md).
     */
    public function upload(UploadedFile $file, Ticket $ticket, int $uploadedBy, int $maxArchivos): TicketAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "servicios/tickets/{$ticket->id}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        return DB::transaction(function () use ($file, $ticket, $uploadedBy, $key, $maxArchivos): TicketAttachment {
            $lockedTicket = Ticket::where('id', $ticket->id)->lockForUpdate()->first();

            if ($lockedTicket === null || $lockedTicket->attachments()->count() >= $maxArchivos) {
                throw new TicketAttachmentLimitExceededException($maxArchivos);
            }

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
                return TicketAttachment::create([
                    'ticket_id'      => $ticket->id,
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
                    Log::error('ticket_attachment_service.storage_cleanup_failed', [
                        'disk'  => self::DISK,
                        'key'   => $key,
                        'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }
        });
    }

    public function presignedUrl(TicketAttachment $attachment): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $attachment->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }
}
