<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Multitenancy\RegionOptions;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * Fase 6a — cambia el contexto regional (país, moneda, zona horaria, locale) de un tenant existente. Parte de los valores actuales
 * del tenant y solo cambia lo que se pasa; `--country` re-deriva moneda/zona/locale del catálogo salvo que se indiquen aparte.
 */
class TenantSetRegionCommand extends Command
{
    protected $signature = 'tenant:set-region {slug} {--country=} {--currency=} {--timezone=} {--locale=}';

    protected $description = 'Cambia el país, la moneda, la zona horaria y el locale de un tenant (ver config/regions.php)';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error('No existe un tenant con slug ['.$this->argument('slug').'].');

            return self::FAILURE;
        }

        $country = $this->option('country');
        $actual = $tenant->region();

        $region = RegionOptions::resolve(
            $country ?: $actual['country'],
            $this->option('currency') ?: ($country ? null : $actual['currency']),
            $this->option('timezone') ?: ($country ? null : $actual['timezone']),
            $this->option('locale') ?: ($country ? null : $actual['locale']),
        );
        if (is_string($region)) {
            $this->error($region);

            return self::FAILURE;
        }

        $tenant->update($region);
        $this->info("Tenant [{$tenant->slug}]: {$region['country']} / {$region['currency']} / {$region['timezone']} / {$region['locale']}.");
        if ($region['timezone'] !== $actual['timezone']) {
            $this->warn('La zona horaria cambió: las fechas ya guardadas se interpretan como hora local de la zona NUEVA. Hacelo solo si el tenant aún no tiene datos.');
        }

        return self::SUCCESS;
    }
}
