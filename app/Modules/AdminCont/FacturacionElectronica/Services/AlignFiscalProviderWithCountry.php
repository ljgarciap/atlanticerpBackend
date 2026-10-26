<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;

/**
 * Fase 6b — deja el proveedor fiscal de un tenant coherente con su país: si el proveedor guardado no sirve en el país del tenant
 * (p. ej. `digifact`, de Panamá, en un tenant de Colombia), pasa al que corresponde por omisión (`FiscalProviderRegistry::porDefecto`,
 * que sin paquete fiscal es `none`). No toca un proveedor que SÍ sirve en el país. Lo usan `tenant:create` y `tenant:set-region`.
 */
final class AlignFiscalProviderWithCountry
{
    public function __construct(private readonly FiscalProviderRegistry $registro) {}

    /**
     * @return array{anterior: string, nuevo: string}|null los dos valores si hubo cambio; null si ya era coherente
     */
    public function handle(Tenant $tenant): ?array
    {
        $pais = $tenant->region()['country'];

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        try {
            $settings = AdminContFiscalSettings::current();
            if ($this->registro->permitido($settings->pac_provider, $pais)) {
                return null;
            }

            $anterior = $settings->pac_provider;
            $nuevo = $this->registro->porDefecto($pais);
            $settings->forceFill(['pac_provider' => $nuevo])->save();

            return ['anterior' => $anterior, 'nuevo' => $nuevo];
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
