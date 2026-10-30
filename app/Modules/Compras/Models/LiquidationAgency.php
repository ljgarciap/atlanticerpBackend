<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-210 (REQ-147, alcance reducido) — agencia aduanal/de liquidación para órdenes de importación.
 * Único campo obligatorio: `name`, mismo patrón que Provider (REQ-126 RN1).
 *
 * SCRUM-254 (REQ-191) — `pending_payment_amount`/`paid_amount`/`last_payment_date`/
 * `next_payment_date` son carga MANUAL de Compras (mock, sin RN aprobada — ver migración
 * `add_payment_tracking_to_compras_liquidation_agencies_table`), no un historial de pagos.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 * @property numeric-string $pending_payment_amount
 * @property numeric-string $paid_amount
 * @property \Illuminate\Support\Carbon|null $last_payment_date
 * @property \Illuminate\Support\Carbon|null $next_payment_date
 */
class LiquidationAgency extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'contact_name', 'phone', 'email', 'notes',
        'pending_payment_amount', 'paid_amount', 'last_payment_date', 'next_payment_date',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'pending_payment_amount' => 'decimal:2',
        'paid_amount'            => 'decimal:2',
        'last_payment_date'      => 'date:Y-m-d',
        'next_payment_date'      => 'date:Y-m-d',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'liquidation_agencies';
        }

        return "{$tenant->slug}_compras.liquidation_agencies";
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * SCRUM-255 (REQ-192) — reemplaza el contacto único inline (`contact_name`/`phone`/`email`,
     * deprecated pero no eliminado, ver migración de backfill).
     *
     * @return HasMany<LiquidationAgencyContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(LiquidationAgencyContact::class);
    }
}
