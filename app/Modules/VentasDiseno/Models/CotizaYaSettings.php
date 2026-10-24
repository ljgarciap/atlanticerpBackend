<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1120 — fila única con el Cliente Master y el Subcliente «CotizaYA» de este entorno. Toda cotización de
 * CotizaYA los usa; el flujo comercial nunca puede (ver `CotizaYaClientGuard`).
 *
 * @property int $id
 * @property int $master_client_id
 * @property int $sub_client_id
 */
class CotizaYaSettings extends Model
{
    /** @var list<string> */
    protected $fillable = ['master_client_id', 'sub_client_id'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cotizaya_settings';
        }

        return "{$tenant->slug}_ventas_diseno.cotizaya_settings";
    }

    /** Null si el entorno todavía no sembró el cliente (CotizaYaClientSeeder). */
    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }
}
