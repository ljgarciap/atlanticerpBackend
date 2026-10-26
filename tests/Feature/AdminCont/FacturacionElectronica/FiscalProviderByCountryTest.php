<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalProviderRegistry;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 6b (docs/specs/fase6-fiscal-por-pais.md) — registro de proveedores de facturación electrónica por país. Panamá ofrece `msl` y
 * `digifact`; un país sin paquete fiscal (Colombia, EE. UU. hasta tener su adaptador) queda en `none`: documentos internos, nada viaja a un PAC.
 */
final class FiscalProviderByCountryTest extends FeatureTestCase
{
    private function registro(): FiscalProviderRegistry
    {
        return app(FiscalProviderRegistry::class);
    }

    private function proveedorActual(): string
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        return AdminContFiscalSettings::current()->pac_provider;
    }

    public function test_el_registro_ofrece_msl_digifact_y_none_en_panama_y_solo_none_en_el_resto(): void
    {
        $this->assertSame(['msl', 'digifact', 'none'], array_keys($this->registro()->paraPais('PA')));
        $this->assertSame(['none'], array_keys($this->registro()->paraPais('CO')));
        $this->assertSame(['none'], array_keys($this->registro()->paraPais('us')));
    }

    public function test_permitido_y_proveedor_por_defecto_segun_el_pais(): void
    {
        $this->assertTrue($this->registro()->permitido('digifact', 'PA'));
        $this->assertFalse($this->registro()->permitido('digifact', 'CO'));
        $this->assertTrue($this->registro()->permitido('none', 'CO'));
        $this->assertFalse($this->registro()->permitido('inventado', 'PA'));

        $this->assertSame('msl', $this->registro()->porDefecto('PA'));
        $this->assertSame('none', $this->registro()->porDefecto('CO'));
        $this->assertSame('none', $this->registro()->porDefecto('US'));
        $this->assertSame('none', $this->registro()->porDefecto('XX'));
    }

    public function test_set_pac_provider_acepta_none_y_digifact_en_panama(): void
    {
        $this->assertSame(0, Artisan::call('admin-contab:set-pac-provider', ['provider' => 'digifact', '--tenant' => 'atlantic']));
        $this->assertSame('digifact', $this->proveedorActual());

        $this->assertSame(0, Artisan::call('admin-contab:set-pac-provider', ['provider' => 'none', '--tenant' => 'atlantic']));
        $this->assertSame('none', $this->proveedorActual());
    }

    public function test_set_pac_provider_rechaza_un_proveedor_que_no_sirve_en_el_pais_del_tenant(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'CO']));

        $this->assertSame(1, Artisan::call('admin-contab:set-pac-provider', ['provider' => 'msl', '--tenant' => 'atlantic']));
        $this->assertStringContainsString('no sirve en el país CO', Artisan::output());
        $this->assertSame('none', $this->proveedorActual());
    }

    public function test_cambiar_el_pais_a_uno_sin_paquete_pasa_el_proveedor_a_none(): void
    {
        $this->assertSame(0, Artisan::call('admin-contab:set-pac-provider', ['provider' => 'digifact', '--tenant' => 'atlantic']));
        $this->assertSame('digifact', $this->proveedorActual());

        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'CO']));

        $this->assertStringContainsString("'digifact' no sirve en CO", Artisan::output());
        $this->assertSame('none', $this->proveedorActual());
        $this->assertTrue(AdminContFiscalSettings::current()->sinFacturacionElectronica());
    }

    public function test_volver_a_panama_no_pisa_un_proveedor_que_sirve(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'CO']));
        $this->assertSame('none', $this->proveedorActual());

        // `none` es válido en cualquier país: no se cambia solo al volver a Panamá (lo decide Gerencia con admin-contab:set-pac-provider).
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'PA']));
        $this->assertSame('none', $this->proveedorActual());
    }

    public function test_el_api_de_configuracion_fiscal_expone_el_estado_y_los_proveedores_del_pais(): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => 'CO']));
        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');

        $r = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/fiscal-settings');

        $r->assertOk()
            ->assertJsonPath('pais', 'CO')
            ->assertJsonPath('pac_provider', 'none')
            ->assertJsonPath('facturacion_electronica_activa', false)
            ->assertJsonPath('pac_providers_disponibles', [['key' => 'none', 'label' => 'Sin facturación electrónica']]);
    }
}
