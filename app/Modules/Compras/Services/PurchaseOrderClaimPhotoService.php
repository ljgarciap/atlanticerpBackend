<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrderClaimPhoto;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** SCRUM-259 (REQ-196) — mismo mecanismo de resiliencia que PurchaseOrderDocumentService/PurchaseOrderPaymentService. */
final class PurchaseOrderClaimPhotoService
{
    private const DISK = 's3-private';

    public function upload(UploadedFile $photo, int $claimId, int $uploadedBy): PurchaseOrderClaimPhoto
    {
        $ext = strtolower($photo->getClientOriginalExtension());
        $key = "compras/claims/{$claimId}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        return DB::transaction(function () use ($photo, $claimId, $uploadedBy, $key): PurchaseOrderClaimPhoto {
            (new CircuitBreaker(self::DISK))->call(function () use ($claimId, $photo, $key): void {
                $uploaded = Storage::disk(self::DISK)->putFileAs("compras/claims/{$claimId}", $photo, basename($key));

                if ($uploaded === false) {
                    throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
                }
            });

            try {
                return PurchaseOrderClaimPhoto::create([
                    'claim_id'    => $claimId,
                    'storage_key' => $key,
                    'uploaded_by' => $uploadedBy,
                ]);
            } catch (\Throwable $e) {
                try {
                    Storage::disk(self::DISK)->delete($key);
                } catch (\Throwable $se) {
                    Log::error('purchase_order_claim_photo.storage_cleanup_failed', [
                        'key' => $key, 'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }
        });
    }

    public function presignUrl(PurchaseOrderClaimPhoto $photo): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($photo->storage_key, now()->addMinutes(15));
    }
}
