<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-428 (REQ-358) — evidencia de una línea de solicitud de ajuste. Mismo mecanismo de
 * resiliencia que `PurchaseOrderClaimPhotoService` (Circuit Breaker + disco privado S3).
 */
final class AdjustmentEvidenceService
{
    private const DISK = 's3-private';

    public function upload(UploadedFile $file, int $requestId): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "bodega/adjustment-requests/{$requestId}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($requestId, $file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs("bodega/adjustment-requests/{$requestId}", $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
            }
        });

        return $key;
    }

    public function presignUrl(string $storageKey): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($storageKey, now()->addMinutes(15));
    }
}
