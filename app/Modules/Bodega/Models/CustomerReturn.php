<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md. Ciclo completo de devolución de cliente, siempre creada a
 * partir de una Guía de Entrega real de un `Order` ya despachado/entregado (REQ-414) —
 * `order_id` NOT NULL, no existe "devolución libre".
 *
 * `status` — una de 4 (REQ-413): pendiente -> esperando_nota_credito -> finalizado, o
 * pendiente -> rechazada. El sub-paso "sin firma"/"con firma" dentro de "Pendiente" se deriva de
 * `signed_document_path IS NULL` (`hasSignedDocument()`), no es un status aparte.
 *
 * `credit_note_notified_at` — sellado automático al confirmar recepción física (REQ-410 RN1),
 * simula el aviso a Administración & Contabilidad (módulo que no existe todavía, mismo criterio
 * de stub que `Order.invoice_ready`).
 *
 * @property int              $id
 * @property string           $return_number
 * @property int               $order_id
 * @property string            $status
 * @property string            $contact_name
 * @property string            $contact_phone
 * @property string|null       $signed_document_path
 * @property \Carbon\Carbon|null $signed_document_uploaded_at
 * @property int|null          $received_by
 * @property \Carbon\Carbon|null $received_at
 * @property int|null          $destination_warehouse_id
 * @property \Carbon\Carbon|null $credit_note_notified_at
 * @property \Carbon\Carbon|null $finalized_at
 * @property int|null          $rejected_by
 * @property \Carbon\Carbon|null $rejected_at
 * @property string|null       $rejection_reason
 * @property int                $created_by
 * @property \Carbon\Carbon     $created_at
 * @property \Carbon\Carbon     $updated_at
 * @property-read Order         $order
 * @property-read User|null     $receivedBy
 * @property-read Warehouse|null $destinationWarehouse
 * @property-read User|null     $rejectedBy
 * @property-read User|null     $createdBy
 * @property-read Collection<int, CustomerReturnLine> $lines
 */
class CustomerReturn extends Model
{
    use LogsAuditTrail;

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_ESPERANDO_NOTA_CREDITO = 'esperando_nota_credito';

    public const STATUS_FINALIZADO = 'finalizado';

    public const STATUS_RECHAZADA = 'rechazada';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_ESPERANDO_NOTA_CREDITO,
        self::STATUS_FINALIZADO,
        self::STATUS_RECHAZADA,
    ];

    /** @var list<string> */
    protected $fillable = [
        'return_number', 'order_id', 'status', 'contact_name', 'contact_phone',
        'signed_document_path', 'signed_document_uploaded_at',
        'received_by', 'received_at', 'destination_warehouse_id',
        'credit_note_notified_at', 'finalized_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signed_document_uploaded_at' => 'datetime',
            'received_at'                => 'datetime',
            'credit_note_notified_at'    => 'datetime',
            'finalized_at'               => 'datetime',
            'rejected_at'                => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'customer_returns';
        }

        return "{$tenant->slug}_bodega.customer_returns";
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CustomerReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomerReturnLine::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDIENTE;
    }

    public function isAwaitingCreditNote(): bool
    {
        return $this->status === self::STATUS_ESPERANDO_NOTA_CREDITO;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZADO;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_RECHAZADA;
    }

    /** REQ-413 — sub-paso derivado dentro de "Pendiente": ¿ya se cargó el documento firmado? */
    public function hasSignedDocument(): bool
    {
        return $this->signed_document_path !== null;
    }
}
