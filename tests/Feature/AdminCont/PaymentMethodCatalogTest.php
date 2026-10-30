<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use App\Modules\AdminCont\Services\PaymentMethods;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14b (docs/specs/fase14-parametrizacion.md) — las formas de pago pasan de constantes del código a un catálogo por empresa con tres
 * propiedades de comportamiento. La migración no cambia ningún valor actual; una forma propia se acepta, hereda el comportamiento que se le
 * configure, y las dos de sistema quedan bloqueadas.
 */
final class PaymentMethodCatalogTest extends FeatureTestCase
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

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    private function contabilidad(): array
    {
        return $this->authHeaders($this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab'));
    }

    public function test_el_sembrado_conserva_los_valores_de_siempre(): void
    {
        $this->assertSame(
            ['transferencia', 'cheque', 'efectivo', 'tarjeta', 'deposito', 'yappy', 'link_pago', 'retencion_impuestos', 'ajuste_cuenta'],
            PaymentMethods::keys(),
        );
        // Sin cuenta bancaria: solo las dos de siempre. Inmediatas: efectivo y esas dos. Caja chica: las 4 que ya existían.
        $this->assertSame(['retencion_impuestos', 'ajuste_cuenta'], array_values(array_filter(PaymentMethods::keys(), PaymentMethods::sinCuentaBancaria(...))));
        $this->assertSame(['efectivo', 'retencion_impuestos', 'ajuste_cuenta'], PaymentMethods::inmediatos());
        $this->assertSame(['transferencia', 'cheque', 'efectivo', 'yappy'], PaymentMethods::cajaChicaKeys());
        $this->assertSame('Depósito bancario', PaymentMethods::label('deposito'));
    }

    public function test_las_dos_de_sistema_no_se_borran_ni_cambian_de_propiedades(): void
    {
        $h = $this->superadmin();
        $this->activarTenant();
        $id = (int) CatalogEntry::query()->where('domain', 'payment_method')->where('key', 'retencion_impuestos')->value('id');

        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/payment_method/{$id}")->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$id}", [
            'label' => 'Retención', 'props' => ['sin_cuenta_bancaria' => false, 'inmediato' => true, 'caja_chica' => false],
        ])->assertStatus(422);
        // Solo se puede renombrar.
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$id}", [
            'label' => 'Retención de impuestos (ITBMS)', 'props' => ['sin_cuenta_bancaria' => true, 'inmediato' => true, 'caja_chica' => false],
        ])->assertOk();
    }

    public function test_una_forma_propia_se_acepta_y_hereda_su_comportamiento(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/payment_method', [
            'key' => 'ventanilla', 'label' => 'Ventanilla bancaria', 'props' => ['sin_cuenta_bancaria' => true, 'inmediato' => true],
        ])->assertCreated();

        $this->activarTenant();
        $this->assertContains('ventanilla', PaymentMethods::keys());
        $this->assertTrue(PaymentMethods::sinCuentaBancaria('ventanilla'));
        $this->assertTrue(PaymentMethods::inmediato('ventanilla'));
        $this->assertContains('ventanilla', PaymentMethods::inmediatos());
        $this->assertNotContains('ventanilla', PaymentMethods::cajaChicaKeys());

        // Sin cuenta bancaria: el abono no la pide para la forma nueva, y se rechaza una clave que no está en el catálogo.
        $master = MasterClient::create(['name' => 'Cliente Pago '.uniqid()]);
        $base = ['master_client_id' => $master->id, 'monto_original' => 100, 'referencia' => 'REF-'.uniqid()];
        $c = $this->contabilidad();
        $this->withHeaders($c)->postJson('/api/admin-contab/abonos', $base + ['metodo_pago' => 'ventanilla'])->assertCreated();
        $this->withHeaders($c)->postJson('/api/admin-contab/abonos', $base + ['metodo_pago' => 'no_existe'])->assertStatus(422);
    }

    public function test_una_forma_desactivada_no_se_ofrece_y_una_en_uso_no_se_desactiva(): void
    {
        $h = $this->superadmin();
        $this->activarTenant();
        $yappy = (int) CatalogEntry::query()->where('domain', 'payment_method')->where('key', 'yappy')->value('id');
        $link = (int) CatalogEntry::query()->where('domain', 'payment_method')->where('key', 'link_pago')->value('id');

        // En uso (un reporte de caja chica pagado con Yappy): no se desactiva.
        AdminContPettyCashReport::create(['numero' => 'CC-'.uniqid(), 'forma_pago' => 'yappy']);
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$yappy}", ['label' => 'Yappy', 'is_active' => false])->assertStatus(422);

        // Sin uso: se desactiva, deja de ofrecerse, pero su etiqueta se sigue resolviendo.
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$link}", ['label' => 'Link de pago', 'is_active' => false])->assertOk();
        $this->activarTenant();
        $this->assertNotContains('link_pago', CatalogService::keys('payment_method'));
        $this->assertSame('Link de pago', PaymentMethods::label('link_pago'));
    }

    public function test_la_caja_chica_solo_acepta_las_formas_marcadas(): void
    {
        $this->assertContains('yappy', PaymentMethods::cajaChicaKeys());
        $this->assertNotContains('tarjeta', PaymentMethods::cajaChicaKeys());
    }
}
