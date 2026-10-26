<?php

namespace App\Shared\Multitenancy;

use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'domain', 'is_active', 'country', 'currency', 'timezone', 'locale'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Fase 6a — contexto regional de la empresa. Los valores por omisión (Panamá) los pone la migración de `tenants`.
     *
     * @return array{country: string, currency: string, timezone: string, locale: string}
     */
    public function region(): array
    {
        return [
            'country' => (string) ($this->country ?? 'PA'),
            'currency' => (string) ($this->currency ?? 'USD'),
            'timezone' => (string) ($this->timezone ?? 'America/Panama'),
            'locale' => (string) ($this->locale ?? 'es-PA'),
        ];
    }

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
