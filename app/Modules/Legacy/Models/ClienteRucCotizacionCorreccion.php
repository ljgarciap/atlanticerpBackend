<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1137 F3 — registro de reversa de `legacy:fix-clientes-ruc --cotizaciones` (una fila por cotización ajustada y por corrida).
 *
 * @property int         $id
 * @property string      $run_id
 * @property int         $quote_id
 * @property int         $sub_client_id
 * @property string      $ruc_anterior
 * @property string      $ruc_nuevo
 * @property string      $operador
 * @property \Carbon\Carbon      $aplicada_at
 * @property \Carbon\Carbon|null $revertida_at
 */
class ClienteRucCotizacionCorreccion extends Model
{
    /** @var list<string> */
    protected $fillable = ['run_id', 'quote_id', 'sub_client_id', 'ruc_anterior', 'ruc_nuevo', 'operador', 'aplicada_at', 'revertida_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aplicada_at' => 'datetime', 'revertida_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cotizacion_ruc_correcciones';
        }

        return "{$tenant->slug}_legacy.cotizacion_ruc_correcciones";
    }
}
