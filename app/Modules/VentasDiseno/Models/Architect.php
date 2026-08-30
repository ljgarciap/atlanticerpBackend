<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-030 / SCRUM-122: arquitecto con BD propia (distinta de sub_client_contacts/
 * pipeline_card_contacts, que son contactos propios de un cliente puntual), pero
 * acotado a un Subcliente ya elegido — mismo patrón que SalesProject. Ya no es un
 * directorio global: sub_client_id nullable solo por compatibilidad con los
 * registros creados antes de SCRUM-122 (quedan huérfanos, invisibles en cualquier
 * búsqueda acotada).
 *
 * @property int         $id
 * @property int|null    $sub_client_id
 * @property string       $name
 * @property string|null $phone
 * @property string|null $email
 * @property SubClient|null $subClient
 */
class Architect extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['sub_client_id', 'name', 'phone', 'email'];

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'architects';
        }

        return "{$tenant->slug}_ventas_diseno.architects";
    }
}
