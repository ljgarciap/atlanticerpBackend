<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-257→261 (REQ-194→198) — reclamo a proveedor por mercancía defectuosa, incompleta o
 * incorrecta.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property string|null $description
 * @property string|null $expected_resolution
 * @property string $status
 * @property Carbon $status_changed_at
 * @property Carbon|null $resolved_at
 * @property int|null $service_ticket_id
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class PurchaseOrderClaim extends Model
{
    public const STATUS_EN_REVISION = 'en_revision';

    public const STATUS_RESUELTO = 'resuelto';

    // SCRUM-260 (hallazgo de Gerencia Test 2026-08-10) — "Tipo de resolución" pasa de texto libre
    // a las 5 opciones fijas del requerimiento (antes cualquier string). Reclamos ya existentes
    // creados con texto libre conservan su valor tal cual, solo lectura — este catálogo aplica
    // desde acá en adelante, no se backfillea data vieja.
    public const RESOLUTION_REPOSICION = 'reposicion';

    public const RESOLUTION_NOTA_CREDITO_FAVOR = 'nota_credito_favor';

    public const RESOLUTION_NOTA_CREDITO_REEMBOLSO = 'nota_credito_reembolso';

    public const RESOLUTION_ENVIO_REPUESTOS = 'envio_repuestos';

    public const RESOLUTION_NEGADO = 'negado';

    /** @var list<string> */
    public const RESOLUTION_TYPES = [
        self::RESOLUTION_REPOSICION, self::RESOLUTION_NOTA_CREDITO_FAVOR,
        self::RESOLUTION_NOTA_CREDITO_REEMBOLSO, self::RESOLUTION_ENVIO_REPUESTOS, self::RESOLUTION_NEGADO,
    ];

    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id', 'description', 'expected_resolution', 'status',
        'status_changed_at', 'resolved_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'claims';
        }

        return "{$tenant->slug}_compras.claims";
    }

    /** REQ-196 — "R-1234", derivado de `id`, sin tabla de contador separada. */
    public function code(): string
    {
        return 'R-'.(1000 + $this->id);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseOrderClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderClaimLine::class, 'claim_id');
    }

    /** @return HasMany<PurchaseOrderClaimPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(PurchaseOrderClaimPhoto::class, 'claim_id');
    }

    /** REQ-196 — monto afectado, derivado de las líneas (cantidad afectada × costo unitario real de la orden). */
    public function totalAmount(): float
    {
        return (float) $this->lines->sum(fn (PurchaseOrderClaimLine $line): float => $line->subtotal());
    }

    /** REQ-194 — "⚠️ Atención": en revisión y ya superó el umbral configurado de días. */
    public function needsAttention(int $attentionDays): bool
    {
        return $this->status === self::STATUS_EN_REVISION
            && $this->status_changed_at->diffInDays(now()) >= $attentionDays;
    }
}
