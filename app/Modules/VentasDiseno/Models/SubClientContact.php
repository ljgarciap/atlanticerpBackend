<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-066: contactos propios del Subcliente, reutilizables entre proyectos y (a
 * futuro, fuera de alcance) desde Cotización — distintos de PipelineCardContact,
 * que son propios de una tarjeta puntual.
 *
 * @property int         $id
 * @property int         $sub_client_id
 * @property string      $name
 * @property string      $role
 * @property string|null $phone
 * @property string|null $email
 * @property-read \Illuminate\Database\Eloquent\Relations\Pivot $pivot
 */
class SubClientContact extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['sub_client_id', 'name', 'role', 'phone', 'email'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sub_client_contacts';
        }

        return "{$tenant->slug}_ventas_diseno.sub_client_contacts";
    }

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }
}
