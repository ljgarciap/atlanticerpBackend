<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1168 F3 — una línea de un cruce: de qué fuente sale el monto (`cobro` | `abono` | `nota_credito`) y a qué factura va.
 *
 * @property int    $id
 * @property int    $compensation_id
 * @property string $source_type
 * @property int    $source_id
 * @property int    $invoice_id
 * @property float  $monto
 */
class AdminContCompensationLine extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_COBRO        = 'cobro';
    public const SOURCE_ABONO        = 'abono';
    public const SOURCE_NOTA_CREDITO = 'nota_credito';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monto' => 'float', 'created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'compensation_lines' : "{$tenant->slug}_admin_contab.compensation_lines";
    }
}
