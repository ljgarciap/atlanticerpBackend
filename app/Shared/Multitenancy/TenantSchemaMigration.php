<?php

namespace App\Shared\Multitenancy;

use Illuminate\Database\Migrations\Migration;

/**
 * Las tablas de tenant se crean con el nombre de schema calificado
 * (`{slug}_{module}.tabla`) en vez de depender del orden del search_path,
 * para que cada CREATE TABLE caiga siempre en el schema correcto del
 * módulo (auth/crm/servicios) sin importar cuál esté primero.
 */
abstract class TenantSchemaMigration extends Migration
{
    protected string $module;

    protected function table(string $name): string
    {
        return "{$this->schema()}.{$name}";
    }

    protected function schema(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException(
                'No hay tenant activo. Estas migraciones solo corren via "php artisan tenant:create" / tenants:artisan.'
            );
        }

        return "{$tenant->slug}_{$this->module}";
    }
}
