<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 34b — las formas de pago con propiedad `paises` solo se ofrecen en la región de la empresa; lo histórico se sigue resolviendo.
 */
final class PaymentMethodCountriesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activarTenant();
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function pais(string $country): void
    {
        $tenant = Tenant::current();
        $tenant->country = $country;
        $tenant->save();
        $tenant->makeCurrent();
    }

    public function test_yappy_solo_se_ofrece_en_panama(): void
    {
        $this->pais('PA');
        $this->assertContains('yappy', PaymentMethods::keys());

        $this->pais('CO');
        $this->assertNotContains('yappy', PaymentMethods::keys());
        $this->assertNotContains('yappy', PaymentMethods::cajaChicaKeys());
        $this->assertSame('Yappy', PaymentMethods::label('yappy'), 'lo ya cobrado con ella se sigue mostrando');
        $this->assertContains('transferencia', PaymentMethods::keys());
    }

    public function test_una_forma_sin_paises_se_ofrece_en_todos_y_una_propia_se_limita(): void
    {
        $super = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
        $this->withHeaders($super)->postJson('/api/admin/catalogs/payment_method', ['key' => 'pse', 'label' => 'PSE', 'props' => ['paises' => ['CO']]])->assertCreated();
        $this->withHeaders($super)->postJson('/api/admin/catalogs/payment_method', ['key' => 'x', 'label' => 'X', 'props' => ['paises' => ['ZZ']]])->assertStatus(422);

        $this->activarTenant();
        $this->pais('CO');
        $this->assertContains('pse', PaymentMethods::keys());
        $this->pais('PA');
        $this->assertNotContains('pse', PaymentMethods::keys());
        $this->assertContains('pse', CatalogEntry::query()->where('domain', 'payment_method')->pluck('key')->all());
        $this->assertContains('pse', array_keys(CatalogService::labels('payment_method', onlyActive: false)));
    }
}
