<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-487 — comprobante de soporte de una Nota de Crédito (imagen o PDF), obligatorio según el
 * caso (ver `RegisterCreditNoteRequest`). Mismo patrón que `AdminContPaymentAttachmentService`
 * (disco `s3-private`, presigned al leer) pero sin tabla de adjuntos propia — `credit_notes` solo
 * guarda 1 comprobante como string (`comprobante_url`, ya existente desde Batch 10), así que este
 * servicio devuelve directamente el storage key en vez de crear un modelo.
 */
final class AdminContCreditNoteAttachmentService
{
    private const DISK = 's3-private';

    /** Presigned URL lifetime (minutes) — mismo criterio que AdminContPaymentAttachmentService. */
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, int $creditNoteId): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/credit-notes/{$creditNoteId}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

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

        return $key;
    }

    public function presignedUrl(string $storageKey): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $storageKey,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }

    /**
     * Batch 13 (REQ-495) — `credit_notes` no tiene columna `mime_type` (a diferencia de
     * `AdminContPaymentAttachment`, que sí tiene tabla propia): se infiere de la extensión del
     * storage key en vez de agregar una migración solo para esto, o de pedirle el HEAD del
     * archivo real a S3 en cada "Ver comprobante" (costo/latencia innecesarios). Cerrado sobre el
     * mismo whitelist ya validado al subir (`RegisterCreditNoteRequest`/
     * `RegisterCreditNoteCorreccionRequest`: `mimes:jpg,jpeg,png,pdf`) — no hace falta cubrir
     * ningún otro tipo.
     */
    public function guessMimeType(string $storageKey): string
    {
        return match (strtolower(pathinfo($storageKey, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'pdf'         => 'application/pdf',
            default       => 'application/octet-stream',
        };
    }
}
