<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrderPayment;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-252 (REQ-189) — comprobante de pago, mismo mecanismo de resiliencia que
 * PurchaseOrderDocumentService (CircuitBreaker + S3), en un prefijo de key separado
 * (`compras/{orderId}/payments/`) porque un comprobante de pago no es un documento del checklist
 * de Logística (REQ-155) — no comparten tabla ni categorías.
 */
final class PurchaseOrderPaymentService
{
    private const DISK = 's3-private';

    public function register(UploadedFile $proof, int $orderId, float $amount, int $registeredBy): PurchaseOrderPayment
    {
        $ext = strtolower($proof->getClientOriginalExtension());
        $key = "compras/{$orderId}/payments/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        return DB::transaction(function () use ($proof, $orderId, $amount, $registeredBy, $key): PurchaseOrderPayment {
            (new CircuitBreaker(self::DISK))->call(function () use ($orderId, $proof, $key): void {
                $uploaded = Storage::disk(self::DISK)->putFileAs("compras/{$orderId}/payments", $proof, basename($key));

                if ($uploaded === false) {
                    throw new \RuntimeException("S3 upload failed for disk [".self::DISK."] key [{$key}]");
                }
            });

            try {
                return PurchaseOrderPayment::create([
                    'purchase_order_id'         => $orderId,
                    'amount'                    => $amount,
                    'payment_proof_storage_key' => $key,
                    'registered_by'             => $registeredBy,
                ]);
            } catch (\Throwable $e) {
                try {
                    Storage::disk(self::DISK)->delete($key);
                } catch (\Throwable $se) {
                    Log::error('purchase_order_payment.storage_cleanup_failed', [
                        'key' => $key, 'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }
        });
    }

    public function presignProofUrl(PurchaseOrderPayment $payment): string
    {
        return Storage::disk(self::DISK)->temporaryUrl($payment->payment_proof_storage_key, now()->addMinutes(15));
    }
}
