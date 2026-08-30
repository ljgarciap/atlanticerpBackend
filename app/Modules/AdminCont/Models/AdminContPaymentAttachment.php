<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 6 de Cobros (SCRUM-545, REQ-468) — comprobante de pago adjuntado al registrar un cobro.
 * Mismo patrón que `Servicios\Models\TicketAttachment` (siempre privado, sin versionado), a lo
 * sumo 1 por `payment_id`.
 *
 * @property int         $id
 * @property int         $payment_id
 * @property string      $nombre_archivo
 * @property string      $storage_key
 * @property int         $size_bytes
 * @property string      $mime_type
 * @property int|null    $uploaded_by
 * @property \Carbon\Carbon $created_at
 * @property-read AdminContPayment $payment
 * @property-read User|null $uploader
 */
class AdminContPaymentAttachment extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'payment_id', 'nombre_archivo', 'storage_key', 'size_bytes', 'mime_type', 'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_attachments';
        }

        return "{$tenant->slug}_admin_contab.payment_attachments";
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
