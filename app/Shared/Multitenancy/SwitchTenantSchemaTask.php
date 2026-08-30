<?php

namespace App\Shared\Multitenancy;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Sustituye a SwitchTenantDatabaseTask del paquete: en vez de cambiar de base
 * de datos, cambia el `search_path` de Postgres a los schemas del tenant
 * (uno por módulo: auth, crm, servicios) más `public` como fallback.
 */
class SwitchTenantSchemaTask implements SwitchTenantTask
{
    use UsesMultitenancyConfig;

    public function makeCurrent(IsTenant $tenant): void
    {
        /** @var Tenant $tenant */
        $this->setSearchPath($tenant->schemas());
    }

    public function forgetCurrent(): void
    {
        $this->setSearchPath([]);
    }

    /**
     * @param array<int, string> $schemas
     */
    protected function setSearchPath(array $schemas): void
    {
        $quoted = array_map(
            fn (string $schema): string => '"'.str_replace('"', '', $schema).'"',
            [...$schemas, 'public'],
        );

        DB::connection($this->tenantDatabaseConnectionName())
            ->statement('SET search_path TO '.implode(', ', $quoted));
    }
}
