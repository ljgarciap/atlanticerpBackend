<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 19 de Admin&Cont (SCRUM-602, REQ-525) — constancia fiscal de retención entregada por el
 * cliente, distinta del comprobante de pago (`AdminContPaymentAttachment`). A lo sumo 1 por
 * `payment_id`, pero a diferencia de ese modelo, ACÁ se reemplaza de verdad (`updated_at` real) —
 * ver `AdminContPaymentRetentionAttachmentService`.
 *
 * @property int         $id
 * @property int         $payment_id
 * @property string      $nombre_archivo
 * @property string      $storage_key
 * @property int         $size_bytes
 * @property string      $mime_type
 * @property int|null    $uploaded_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read AdminContPayment $payment
 * @property-read User|null $uploader
 */
class AdminContPaymentRetentionAttachment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payment_id', 'nombre_archivo', 'storage_key', 'size_bytes', 'mime_type', 'uploaded_by',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_retention_attachments';
        }

        return "{$tenant->slug}_admin_contab.payment_retention_attachments";
    }

    /** @return BelongsTo<AdminContPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(AdminContPayment::class, 'payment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
