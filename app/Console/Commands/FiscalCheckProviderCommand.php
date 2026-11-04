<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 24 (D) — comprueba, de SOLO LECTURA, el proveedor fiscal que el tenant tenga configurado (cualquiera: no hay uno privilegiado).
 * El cableado de un proveedor es opcional: sin proveedor («none»/apagado) o sin credenciales no hay nada que validar y NO es un error;
 * solo cuando hay credenciales y se pasa `--ruc` consulta ese receptor por el contrato `PacProviderContract`. No certifica ni emite.
 */
final class FiscalCheckProviderCommand extends Command
{
    protected $signature = 'fiscal:check-provider {--tenant=atlantic : Slug del tenant} {--ruc= : RUC/cédula a consultar (prueba la conexión)} {--allow-production : permite la consulta si el ambiente del tenant es producción}';

    protected $description = 'Comprueba (solo lectura) el proveedor fiscal configurado del tenant; sin proveedor o sin credenciales no hay nada que validar';

    public function handle(PacProviderFactory $fabrica): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $settings = AdminContFiscalSettings::current();
        $this->info("Tenant: {$slug} · proveedor: {$settings->pac_provider} · ambiente: {$settings->pac_ambiente}");

        if ($settings->sinFacturacionElectronica()) {
            $this->info('Sin facturación electrónica: los documentos son internos. Nada que validar.');

            return self::SUCCESS;
        }
        if (! $fabrica->tieneCredencialesReales()) {
            $this->warn("El proveedor '{$settings->pac_provider}' no tiene credenciales en el .env: pendiente, no es un error. Nada que validar.");

            return self::SUCCESS;
        }
        if ($settings->pac_ambiente === 'production' && ! $this->option('allow-production')) {
            $this->error('El ambiente del tenant es producción: la consulta exige --allow-production.');

            return self::INVALID;
        }

        $ruc = (string) $this->option('ruc');
        if ($ruc === '') {
            $this->info('Credenciales presentes. Pase --ruc=<RUC de prueba> para comprobar la conexión.');

            return self::SUCCESS;
        }

        try {
            $resultado = $fabrica->make()->validarReceptor($ruc);
        } catch (Throwable $e) {
            $this->error('❌ No se pudo consultar: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $resultado->disponible) {
            $this->warn('⚠️  El servicio no está disponible (circuito abierto).');

            return self::FAILURE;
        }
        $this->line('Receptor válido: '.($resultado->valido ? 'sí' : 'no'));
        $this->line('Nombre: '.($resultado->nombre ?? '—').' · DV: '.($resultado->dv ?? '—').' · Tipo: '.($resultado->tipo ?? '—'));

        return $resultado->valido ? self::SUCCESS : self::FAILURE;
    }
}
