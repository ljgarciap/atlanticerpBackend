<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1137 F3 — registro de reversa de `legacy:fix-clientes-ruc` (una fila por sub_client corregido y por corrida).
 *
 * @property int         $id
 * @property string      $run_id
 * @property int         $sub_client_id
 * @property int         $icg_codcliente
 * @property string      $tax_id_anterior
 * @property string|null $tax_id_dv_anterior
 * @property string      $tax_id_nuevo
 * @property string|null $tax_id_dv_nuevo
 * @property string      $operador
 * @property \Carbon\Carbon      $aplicada_at
 * @property \Carbon\Carbon|null $revertida_at
 */
class ClienteRucCorreccion extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'run_id', 'sub_client_id', 'icg_codcliente', 'tax_id_anterior', 'tax_id_dv_anterior',
        'tax_id_nuevo', 'tax_id_dv_nuevo', 'operador', 'aplicada_at', 'revertida_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aplicada_at' => 'datetime', 'revertida_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cliente_ruc_correcciones';
        }

        return "{$tenant->slug}_legacy.cliente_ruc_correcciones";
    }
}
