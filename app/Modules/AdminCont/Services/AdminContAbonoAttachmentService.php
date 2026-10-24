<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-902 — comprobante de pago de un Abono (opcional, JPG/PNG/PDF). Mismo patrón que
 * `AdminContCreditNoteAttachmentService` (sin tabla de adjuntos propia, `abonos.comprobante_url`
 * guarda el storage key directo).
 */
final class AdminContAbonoAttachmentService
{
    private const DISK = 's3-private';
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, int $abonoId): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/abonos/{$abonoId}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
            }
        });

        return $key;
    }

    public function presignedUrl(string $storageKey): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($storageKey, now()->addMinutes(self::PRESIGNED_URL_TTL));
    }
}
