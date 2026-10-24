<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int|null    $sub_client_id
 * @property string      $name
 * @property string|null $tag
 * @property SubClient|null $subClient
 */
class SalesProject extends Model
{
    /** @var list<string> */
    protected $fillable = ['sub_client_id', 'name', 'tag'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sales_projects';
        }

        return "{$tenant->slug}_ventas_diseno.sales_projects";
    }

    /**
     * SCRUM-1121 — asociados del proyecto (arquitectos, diseñadores, contratistas...): compartidos por la tarjeta de Pipeline y todas las
     * cotizaciones del proyecto.
     *
     * @return BelongsToMany<Architect, $this>
     */
    public function associates(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null ? "{$tenant->slug}_ventas_diseno.sales_project_associates" : 'sales_project_associates';

        return $this->belongsToMany(Architect::class, $pivot, 'sales_project_id', 'architect_id')->withTimestamps()->orderBy('name');
    }

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }

    /** @return HasMany<PipelineCard, $this> */
    public function pipelineCards(): HasMany
    {
        return $this->hasMany(PipelineCard::class);
    }

    /**
     * SCRUM-329 Oleada A / Batch A4 (REQ-338) — sin FK directa cotización->proyecto en el otro
     * sentido (mismo caso que `OrderService::resolveApprovedQuote()`), pero acá sí hace falta la
     * relación Eloquent (no solo una query manual) para poder filtrar el buscador de "Status de
     * Pedidos" con `whereHas('pipelineCard.salesProject.quotes', ...)`.
     *
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
