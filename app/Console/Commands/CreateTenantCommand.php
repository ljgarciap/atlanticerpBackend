<?php

namespace App\Console\Commands;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create {slug} {name} {--domain=}';

    protected $description = 'Crea un tenant nuevo: sus schemas Postgres, el registro landlord y corre sus migrations';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $name = $this->argument('name');

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Ya existe un tenant con slug [{$slug}].");

            return self::FAILURE;
        }

        $tenant = new Tenant([
            'name' => $name,
            'slug' => $slug,
            'domain' => $this->option('domain'),
            'is_active' => true,
        ]);
        $tenant->save();

        foreach ($tenant->schemas() as $schema) {
            DB::connection('landlord')->statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
        }

        $this->call('tenants:artisan', [
            'artisanCommand' => 'migrate --force --path=database/migrations/tenant',
            '--tenant' => $tenant->getKey(),
        ]);

        $this->info("Tenant [{$slug}] creado: schemas ".implode(', ', $tenant->schemas()));
        $this->line("Siguiente paso: `php artisan db:seed` y luego `php artisan tenant:create-superadmin {$slug} <correo>` para dar de alta el primer superadmin.");

        return self::SUCCESS;
    }
}
