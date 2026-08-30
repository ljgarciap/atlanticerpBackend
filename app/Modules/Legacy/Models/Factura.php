<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-760 — staging de FACTURASVENTA. Llave natural compuesta de ICG:
 * (`icg_numserie`, `icg_numfactura`, `icg_n`) — UNIQUE, el Loader-A (`FacturaStagingLoader`) hace
 * upsert por esos 3 campos, nunca por `id`. `icg_n` es `string`, no `int` (ver docblock de la
 * migración).
 *
 * Sin líneas propias (a diferencia de `Pedido`/`Albaran`) — FACTURASVENTA es una tabla plana, ver
 * docblock de la migración.
 *
 * `legacy_cliente_id`/`orphan` los resuelve `App\Modules\Legacy\Services\FacturaMatcher` — nunca
 * el Loader-A.
 *
 * Sin `updated_at` — ver docblock de la migración.
 *
 * @property int         $id
 * @property string      $icg_numserie
 * @property int         $icg_numfactura
 * @property string      $icg_n
 * @property int|null    $legacy_cliente_id
 * @property int|null    $icg_codcliente
 * @property int|null    $icg_codvendedor
 * @property \Carbon\Carbon|null $fecha
 * @property string|null $total_neto
 * @property string|null $total_impuestos
 * @property string|null $total_bruto
 * @property string|null $sufactura
 * @property bool        $orphan
 * @property \Carbon\Carbon|null $created_at
 * @property-read Cliente|null $cliente
 */
class Factura extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'icg_numserie', 'icg_numfactura', 'icg_n', 'legacy_cliente_id', 'icg_codcliente',
        'icg_codvendedor', 'fecha', 'total_neto', 'total_impuestos', 'total_bruto', 'sufactura',
        'orphan', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'           => 'datetime',
            'total_neto'      => 'decimal:2',
            'total_impuestos' => 'decimal:2',
            'total_bruto'     => 'decimal:2',
            'orphan'          => 'boolean',
            'created_at'      => 'datetime',
        ];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'legacy_cliente_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'facturas';
        }

        return "{$tenant->slug}_legacy.facturas";
    }
}
