<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPettyCashExpense;
use App\Modules\AdminCont\Models\AdminContPettyCashExpenseAttachment;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-535 RN2 — foto(s) del recibo de un gasto de caja chica. Mismo patrón que
 * `AdminContPaymentAttachmentService`/`Servicios\Services\TicketAttachmentService` (S3 privado,
 * `CircuitBreaker`, cleanup en catch). A diferencia de esos, acepta varias fotos por gasto — sin
 * `lockForUpdate`/límite de cantidad, que no aplican acá.
 */
final class AdminContPettyCashAttachmentService
{
    private const DISK = 's3-private';

    /** Presigned URL lifetime (minutes) — mismo criterio que el resto del módulo. */
    private const PRESIGNED_URL_TTL = 15;

    public function upload(UploadedFile $file, AdminContPettyCashExpense $expense, int $uploadedBy): AdminContPettyCashExpenseAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/petty-cash/{$expense->id}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

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
            return AdminContPettyCashExpenseAttachment::create([
                'expense_id'     => $expense->id,
                'nombre_archivo' => $file->getClientOriginalName(),
                'storage_key'    => $key,
                'size_bytes'     => $file->getSize(),
                'mime_type'      => $file->getMimeType() ?? 'application/octet-stream',
                'uploaded_by'    => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            $this->deleteStorage($key);

            throw $e;
        }
    }

    /** Usado por `AdminContPettyCashService::registerExpenses()` para deshacer fotos ya subidas de
     *  líneas anteriores del mismo lote cuando una línea posterior falla (RN4 — todo o nada). */
    public function deleteStorage(string $key): void
    {
        try {
            Storage::disk(self::DISK)->delete($key);
        } catch (\Throwable $se) {
            Log::error('admin_cont_petty_cash_attachment_service.storage_cleanup_failed', [
                'disk'  => self::DISK,
                'key'   => $key,
                'error' => $se->getMessage(),
            ]);
        }
    }

    /** REQ-535 — URL firmada de 15 min para ver la foto del recibo. */
    public function presignedUrl(AdminContPettyCashExpenseAttachment $attachment): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $attachment->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }
}
