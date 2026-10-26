<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Multitenancy\RegionOptions;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantRegion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 6a (docs/specs/fase6-fiscal-por-pais.md) — contexto regional por tenant: país, moneda, zona horaria y locale. Por omisión es el de
 * Panamá (cero cambio para los tenants existentes); cambiarlo cambia la zona de PHP y de la sesión de Postgres sin tocar el código.
 */
final class TenantRegionTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        // La zona horaria de PHP es global al proceso: no se revierte con la transacción del test.
        Tenant::forgetCurrent();
        date_default_timezone_set('America/Panama');
        config(['app.timezone' => 'America/Panama']);

        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::where('slug', 'atlantic')->firstOrFail();
    }

    private function sessionTimezone(): string
    {
        return (string) DB::selectOne('SHOW TIME ZONE')->TimeZone;
    }

    public function test_un_tenant_existente_conserva_el_contexto_de_panama(): void
    {
        $this->assertSame(
            ['country' => 'PA', 'currency' => 'USD', 'timezone' => 'America/Panama', 'locale' => 'es-PA'],
            $this->tenant()->region(),
        );

        $this->tenant()->makeCurrent();
        $this->assertSame('America/Panama', TenantRegion::timezone());
        $this->assertSame('America/Panama', date_default_timezone_get());
        $this->assertSame('America/Panama', $this->sessionTimezone());
    }

    public function test_set_region_con_colombia_cambia_la_zona_de_php_y_de_postgres_al_activar_el_tenant(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'CO']));
        $this->assertSame(
            ['country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'locale' => 'es-CO'],
            $this->tenant()->region(),
        );

        $this->tenant()->makeCurrent();
        $this->assertSame('America/Bogota', TenantRegion::timezone());
        $this->assertSame('COP', TenantRegion::currency());
        $this->assertSame('America/Bogota', date_default_timezone_get());
        $this->assertSame('America/Bogota', config('app.timezone'));
        $this->assertSame('America/Bogota', $this->sessionTimezone());
    }

    public function test_soltar_el_tenant_devuelve_la_zona_de_la_instalacion(): void
    {
        $this->tenant()->update(['timezone' => 'America/New_York']);
        $this->tenant()->makeCurrent();
        $this->assertSame('America/New_York', date_default_timezone_get());

        Tenant::forgetCurrent();
        $this->assertSame('America/Panama', date_default_timezone_get());
        $this->assertSame('America/Panama', $this->sessionTimezone());
    }

    public function test_el_pais_se_puede_afinar_con_moneda_y_zona_propias(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'US', '--timezone' => 'America/Chicago']));

        $this->assertSame(
            ['country' => 'US', 'currency' => 'USD', 'timezone' => 'America/Chicago', 'locale' => 'en-US'],
            $this->tenant()->region(),
        );
    }

    public function test_set_region_solo_cambia_lo_que_se_pasa(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--currency' => 'cop']));

        $this->assertSame(
            ['country' => 'PA', 'currency' => 'COP', 'timezone' => 'America/Panama', 'locale' => 'es-PA'],
            $this->tenant()->region(),
        );
    }

    public function test_valores_invalidos_se_rechazan_sin_tocar_el_tenant(): void
    {
        foreach ([['--country' => 'ZZ'], ['--timezone' => 'Marte/Olimpo'], ['--currency' => 'DOLAR']] as $opciones) {
            $this->assertSame(1, Artisan::call('tenant:set-region', ['slug' => 'atlantic', ...$opciones]));
        }
        $this->assertSame(1, Artisan::call('tenant:set-region', ['slug' => 'no-existe', '--country' => 'CO']));

        $this->assertSame('PA', $this->tenant()->region()['country']);
    }

    public function test_region_options_resuelve_defaults_y_errores(): void
    {
        $this->assertSame(
            ['country' => 'PA', 'currency' => 'USD', 'timezone' => 'America/Panama', 'locale' => 'es-PA'],
            RegionOptions::resolve(null, null, null, null),
        );
        $this->assertSame('COP', RegionOptions::resolve('co', null, null, null)['currency']);
        $this->assertIsString(RegionOptions::resolve('XX', null, null, null));
        $this->assertIsString(RegionOptions::resolve('CO', null, 'Nope/Zone', null));
    }

    public function test_el_login_y_auth_me_exponen_la_region_del_tenant(): void
    {
        $this->tenant()->update(['country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'locale' => 'es-CO']);
        $esperada = ['country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'locale' => 'es-CO'];

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/auth/me')->assertOk()->assertJsonPath('region', $esperada);

        $login = $this->postJson('/api/auth/login', ['email' => 'designer@atlantic.test', 'password' => 'Password123!'])->assertOk();
        $this->assertSame($esperada, $login->json('user.region'));
    }
}
