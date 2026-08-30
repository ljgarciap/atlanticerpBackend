<?php

namespace App\Console\Commands;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTenantSchemasCommand extends Command
{
    protected $signature = 'tenant:sync-schemas';

    protected $description = 'Crea (CREATE SCHEMA IF NOT EXISTS) los schemas que falten para cada tenant existente. '
        .'Idempotente: correr en cada deploy antes de tenants:artisan migrate, para que un módulo nuevo con schema '
        .'propio (ej. Tenant::schemas() agrega "{slug}_compras") no rompa la migración en tenants ya creados.';

    public function handle(): int
    {
        foreach (Tenant::all() as $tenant) {
            foreach ($tenant->schemas() as $schema) {
                DB::connection('landlord')->statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
            }

            $this->info("Tenant [{$tenant->slug}]: schemas sincronizados (".implode(', ', $tenant->schemas()).')');
        }

        return self::SUCCESS;
    }
}
