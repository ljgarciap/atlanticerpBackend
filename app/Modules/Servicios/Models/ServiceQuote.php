<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229→234). Cotización de Servicio. Ver docblock de la migración
 * `create_servicios_service_quotes_table` para el porqué de no ser 1:1 con el ticket.
 *
 * `subtotal`/`itbms`/`total` son SIEMPRE recalculados y persistidos por
 * `ServiceQuoteService::recalculateTotals()` (nunca calculados solo en el frontend/PDF) — REQ-233
 * RN: el total mostrado en el formulario debe ser idéntico al que mostrará el documento (Batch 12),
 * así que la fórmula vive acá, una sola vez.
 *
 * @property int         $id
 * @property string|null $numero
 * @property int         $ticket_id
 * @property string      $estado
 * @property float        $subtotal
 * @property float        $discount_percent
 * @property float        $itbms
 * @property float        $total
 * @property string|null $observations
 * @property string|null $conditions
 * @property int|null    $created_by
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $decided_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Ticket $ticket
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceQuoteItem> $items
 */
class ServiceQuote extends Model
{
    use LogsAuditTrail;

    public const ESTADO_DRAFT = 'draft';

    public const ESTADO_SENT = 'sent';

    public const ESTADO_APPROVED = 'approved';

    public const ESTADO_REJECTED = 'rejected';

    /** @var list<string> */
    public const ESTADOS = [self::ESTADO_DRAFT, self::ESTADO_SENT, self::ESTADO_APPROVED, self::ESTADO_REJECTED];

    /** @var list<string> */
    public const ESTADOS_DECISION = [self::ESTADO_APPROVED, self::ESTADO_REJECTED];

    /** @var list<string> */
    protected $fillable = [
        'numero', 'ticket_id', 'estado', 'subtotal', 'discount_percent', 'itbms', 'total',
        'observations', 'conditions', 'created_by', 'sent_at', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal'          => 'float',
            'discount_percent'  => 'float',
            'itbms'             => 'float',
            'total'             => 'float',
            'sent_at'           => 'datetime',
            'decided_at'        => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'service_quotes';
        }

        return "{$tenant->slug}_servicios.service_quotes";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ServiceQuoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ServiceQuoteItem::class);
    }

    /**
     * REQ-233 — única fórmula de totales del módulo (formulario y, en Batch 12, documento). ITBMS
     * es un % configurable (`ServiciosSettingsService::itbmsPercent()`, default 7 — la tasa legal
     * vigente de Panamá, nunca hardcodeada en el cálculo, ver CLAUDE.md § "Nunca").
     *
     * Escenarios verificados (REQ-233): subtotal 1000 / desc 10% -> base 900, itbms 63, total 963.
     * subtotal 500 / desc 0% -> itbms 35, total 535.
     *
     * @return array{subtotal: float, itbms: float, total: float}
     */
    public static function computeTotals(float $subtotal, float $discountPercent, float $itbmsPercent): array
    {
        $subtotal = round($subtotal, 2);
        $base     = round($subtotal * (1 - $discountPercent / 100), 2);
        $itbms    = round($base * ($itbmsPercent / 100), 2);
        $total    = round($base + $itbms, 2);

        return ['subtotal' => $subtotal, 'itbms' => $itbms, 'total' => $total];
    }
}
