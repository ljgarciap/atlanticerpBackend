<?php

namespace App\Shared\Multitenancy;

use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'domain', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Schemas-per-tenant: cada tenant tiene un schema Postgres por módulo,
     * todos con el mismo prefijo `slug`.
     *
     * @return array<int, string>
     */
    public function schemas(): array
    {
        return [
            "{$this->slug}_auth",
            "{$this->slug}_crm",
            "{$this->slug}_servicios",
            "{$this->slug}_notifications",
            "{$this->slug}_ventas_diseno",
            "{$this->slug}_compras",
            "{$this->slug}_bodega",
            "{$this->slug}_legacy",
            "{$this->slug}_admin_contab",
            "{$this->slug}_gerencia",
        ];
    }

    /**
     * El paquete usa esto solo para la estrategia database-per-tenant,
     * que no aplicamos aquí. Se mantiene por compatibilidad con la interfaz
     * IsTenant — devuelve el slug, que es lo que identifica al tenant.
     */
    public function getDatabaseName(): string
    {
        return $this->slug;
    }
}
