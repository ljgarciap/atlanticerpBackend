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
 * SCRUM-1121: pasa a ser la base única de ASOCIADOS (`type`: arquitecto, diseñador, contratista, ingeniero, consultor, otro),
 * global (ya no acotada a un subcliente) y reutilizable entre Cotizaciones y Pipeline; ver `AssociateDuplicateFinder`.
 *
 * @property int         $id
 * @property string      $type
 * @property int|null    $sub_client_id
 * @property string       $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $document
 * @property int|null    $created_by
 * @property SubClient|null $subClient
 */
class Architect extends Model
{
    use LogsAuditTrail;

    /** SCRUM-1121 — tipos de asociado; `architect` es el de siempre (y el único que origina comisiones de Admin&Cont). */
    public const TYPES = ['architect', 'designer', 'contractor', 'engineer', 'consultant', 'other'];

    /** @var list<string> */
    protected $fillable = ['sub_client_id', 'type', 'name', 'phone', 'email', 'document', 'created_by'];

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
