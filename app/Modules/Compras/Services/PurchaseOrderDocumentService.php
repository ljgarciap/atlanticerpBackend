<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-155 — checklist de documentos del envío. Mismo mecanismo de resiliencia que
 * CRM\Services\DocumentService (CircuitBreaker + S3), sin versionado — ningún criterio de
 * aceptación de REQ-155 pide reemplazar un documento ya subido.
 */
final class PurchaseOrderDocumentService
{
    private const DISK = 's3-private';
    private const PRESIGNED_URL_TTL_MINUTES = 15;

    /** Storage keys: `<modulo>/<entidad_id>/<uuid>.<ext>` (ver convención en atlanticerp/CLAUDE.md). */
    public function upload(UploadedFile $file, int $orderId, string $category, int $uploadedBy): PurchaseOrderDocument
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "compras/{$orderId}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        return DB::transaction(function () use ($file, $orderId, $category, $uploadedBy, $key): PurchaseOrderDocument {
            (new CircuitBreaker(self::DISK))->call(function () use ($orderId, $file, $key): void {
                $uploaded = Storage::disk(self::DISK)->putFileAs("compras/{$orderId}", $file, basename($key));

                if ($uploaded === false) {
                    throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
                }
            });

            try {
                return PurchaseOrderDocument::create([
                    'purchase_order_id' => $orderId,
                    'category'          => $category,
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_key'       => $key,
                    'size_bytes'        => $file->getSize(),
                    'mime_type'         => $file->getMimeType() ?? 'application/octet-stream',
                    'uploaded_by'       => $uploadedBy,
                ]);
            } catch (\Throwable $e) {
                try {
                    Storage::disk(self::DISK)->delete($key);
                } catch (\Throwable $se) {
                    Log::error('purchase_order_document.storage_cleanup_failed', [
                        'key' => $key, 'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }
        });
    }

    public function presignUrl(PurchaseOrderDocument $document): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $document->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL_MINUTES),
        );
    }
}
