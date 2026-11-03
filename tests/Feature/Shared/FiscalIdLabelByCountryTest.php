<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantRegion;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 17b — los textos que nombran la identificación fiscal usan la del país de la empresa: «RUC» (Panamá, sin cambios), «NIT» (Colombia) o
 * la de EE. UU. La API de Panamá sigue diciendo «RUC o cédula».
 */
final class FiscalIdLabelByCountryTest extends FeatureTestCase
{
    private function ponerPais(string $pais): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => $pais]));
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_la_etiqueta_sale_del_pais(): void
    {
        $this->assertSame('RUC', TenantRegion::fiscalIdLabel('PA'));
        $this->assertSame('NIT', TenantRegion::fiscalIdLabel('CO'));
        $this->assertSame('RUC o cédula', TenantRegion::fiscalIdPhrase('PA'));
        $this->assertSame('NIT', TenantRegion::fiscalIdPhrase('CO'));
    }

    public function test_los_mensajes_de_validacion_siguen_diciendo_ruc_en_panama_y_nit_en_colombia(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test');
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients/validate-tax-id', [])
            ->assertStatus(422)->assertJsonPath('errors.tax_id.0', 'Ingresa el RUC o la cédula a validar.');

        $this->ponerPais('CO');
        $token = $this->loginAs('vendedor@atlantic.test');
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients/validate-tax-id', [])
            ->assertStatus(422)->assertJsonPath('errors.tax_id.0', 'Ingresa el NIT a validar.');
    }
}
