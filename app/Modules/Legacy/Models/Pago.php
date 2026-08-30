<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-761 — staging de TESORERIA. Llave natural compuesta REAL de ICG (6 columnas: `icg_origen`,
 * `icg_tipodocumento`, `icg_serie`, `icg_numero`, `icg_n`, `icg_posicion`) — UNIQUE, el Loader-A
 * (`PagoStagingLoader`) hace upsert por esos 6 campos, nunca por `id`. Ver docblock de la
 * migración para el porqué de las 6 columnas (no las 4 del sketch abreviado de §4 del diseño).
 *
 * Sin Loader-B ni Matcher (a diferencia de `Albaran`/`Factura`) — TESORERIA no referencia cliente
 * en absoluto en el subconjunto documentado (§2), catálogo de referencia standalone puro, mismo
 * criterio que `Proveedor`.
 *
 * @property int         $id
 * @property string      $icg_origen
 * @property string      $icg_tipodocumento
 * @property string      $icg_serie
 * @property int         $icg_numero
 * @property string      $icg_n
 * @property int         $icg_posicion
 * @property \Carbon\Carbon|null $fecha_documento
 * @property \Carbon\Carbon|null $fecha_vencimiento
 * @property string|null $importe
 * @property int|null    $icg_codformapago
 * @property string|null $estado
 * @property \Carbon\Carbon|null $fecha_saldado
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Pago extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'icg_origen', 'icg_tipodocumento', 'icg_serie', 'icg_numero', 'icg_n', 'icg_posicion',
        'fecha_documento', 'fecha_vencimiento', 'importe', 'icg_codformapago', 'estado',
        'fecha_saldado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_documento'   => 'datetime',
            'fecha_vencimiento' => 'datetime',
            'importe'           => 'decimal:2',
            'fecha_saldado'     => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pagos';
        }

        return "{$tenant->slug}_legacy.pagos";
    }
}
