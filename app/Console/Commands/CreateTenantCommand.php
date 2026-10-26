<?php

namespace App\Console\Commands;

use App\Modules\AdminCont\FacturacionElectronica\Services\AlignFiscalProviderWithCountry;
use App\Shared\Multitenancy\RegionOptions;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create {slug} {name} {--domain=} {--country= : País ISO-3166 del catálogo config/regions.php (por omisión PA)} {--currency=} {--timezone=} {--locale=}';

    protected $description = 'Crea un tenant nuevo: sus schemas Postgres, el registro landlord y corre sus migrations';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $name = $this->argument('name');

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Ya existe un tenant con slug [{$slug}].");

            return self::FAILURE;
        }

        $region = RegionOptions::resolve(
            $this->option('country'),
            $this->option('currency'),
            $this->option('timezone'),
            $this->option('locale'),
        );
        if (is_string($region)) {
            $this->error($region);

            return self::FAILURE;
        }

        $tenant = new Tenant([
            'name' => $name,
            'slug' => $slug,
            'domain' => $this->option('domain'),
            'is_active' => true,
            ...$region,
        ]);
        $tenant->save();

        foreach ($tenant->schemas() as $schema) {
            DB::connection('landlord')->statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
        }

        $this->call('tenants:artisan', [
            'artisanCommand' => 'migrate --force --path=database/migrations/tenant',
            '--tenant' => $tenant->getKey(),
        ]);

        $ajuste = app(AlignFiscalProviderWithCountry::class)->handle($tenant);
        if ($ajuste !== null) {
            $this->line("Proveedor fiscal: '{$ajuste['anterior']}' no sirve en {$tenant->country} → '{$ajuste['nuevo']}' (sin facturación electrónica si es 'none').");
        }

        $this->info("Tenant [{$slug}] creado ({$tenant->country} / {$tenant->currency} / {$tenant->timezone}): schemas ".implode(', ', $tenant->schemas()));
        $this->line("Siguiente paso: `php artisan db:seed` y luego `php artisan tenant:create-superadmin {$slug} <correo>` para dar de alta el primer superadmin.");

        return self::SUCCESS;
    }
}
