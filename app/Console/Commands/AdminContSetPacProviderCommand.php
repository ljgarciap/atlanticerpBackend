<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-943 — `fiscal_settings.pac_provider` es un dato en tabla paramétrica (default de columna
 * 'digifact'), no un valor de código: activar MSL para un tenant real es correr este comando en
 * ESE entorno, nunca cambiar el default de la migración ni hardcodearlo en un seeder de demo.
 * Idempotente — no falla ni vuelve a escribir si el tenant ya está en el valor pedido.
 */
final class AdminContSetPacProviderCommand extends Command
{
    /** @var string */
    protected $signature = 'admin-contab:set-pac-provider
        {provider : msl | digifact}
        {--tenant=atlantic : Slug del tenant}';

    /** @var string */
    protected $description = 'SCRUM-943 — activa un proveedor PAC (dato paramétrico de fiscal_settings, no código)';

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');
        if (! in_array($provider, ['msl', 'digifact'], true)) {
            $this->error("Provider '{$provider}' inválido — usar 'msl' o 'digifact'.");

            return self::FAILURE;
        }

        $tenantSlug = (string) $this->option('tenant');
        $tenant     = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $settings = AdminContFiscalSettings::current();
        if ($settings->pac_provider === $provider) {
            $this->info("Tenant '{$tenantSlug}' ya tiene pac_provider = '{$provider}' — nada que hacer.");

            return self::SUCCESS;
        }

        $anterior = $settings->pac_provider;
        $settings->forceFill(['pac_provider' => $provider])->save();

        $this->info("Tenant '{$tenantSlug}': pac_provider '{$anterior}' → '{$provider}'.");

        return self::SUCCESS;
    }
}
