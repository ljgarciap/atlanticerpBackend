<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1127 — versión guardada de una orden de compra en otro idioma (hoy solo `en`). Ver la migración.
 *
 * @property int                  $id
 * @property int                  $purchase_order_id
 * @property string               $lang
 * @property array<string,string> $lines          purchase_order_line_id => descripción traducida
 * @property string               $source_hash
 * @property int|null             $generated_by
 * @property \Carbon\Carbon       $generated_at
 */
class PurchaseOrderTranslation extends Model
{
    use LogsAuditTrail;

    public const LANG_EN = 'en';

    /** @var list<string> */
    protected $fillable = ['purchase_order_id', 'lang', 'lines', 'source_hash', 'generated_by', 'generated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['lines' => 'array', 'generated_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'purchase_order_translations' : "{$tenant->slug}_compras.purchase_order_translations";
    }
}
