<?php

declare(strict_types=1);

namespace App\Shared\Multitenancy;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Fase 6a — al activar un tenant alinea la zona horaria de PHP (`date_default_timezone_set` y `config('app.timezone')`) y la de la
 * sesión de Postgres con la del tenant; al soltarlo vuelve a la de la instalación. Las columnas de fecha del sistema guardan hora local
 * (`timestamp without time zone`): por eso conviene fijar la zona al crear el tenant y NO cambiarla con datos ya guardados.
 */
class SwitchTenantRegionTask implements SwitchTenantTask
{
    use UsesMultitenancyConfig;

    /** Zona de la instalación (la que había antes de activar el primer tenant). */
    private static ?string $installationTimezone = null;

    public function makeCurrent(IsTenant $tenant): void
    {
        self::$installationTimezone ??= (string) config('app.timezone');

        /** @var Tenant $tenant */
        $timezone = $tenant->region()['timezone'];
        $this->apply(TenantRegion::isValidTimezone($timezone) ? $timezone : self::$installationTimezone);
    }

    public function forgetCurrent(): void
    {
        if (self::$installationTimezone !== null) {
            $this->apply(self::$installationTimezone);
        }
    }

    private function apply(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
        DB::connection($this->tenantDatabaseConnectionName())->statement("SET TIME ZONE '".str_replace("'", '', $timezone)."'");
    }
}
