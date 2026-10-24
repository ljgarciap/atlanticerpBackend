<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\SandboxFiscalFixtures;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1117 — `qa:fix-demo-fiscal-data` lleva los sub-clientes demo de QA a los RUC del sandbox de MSL. */
final class QaFixDemoFiscalDataCommandTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->stubReceptorGuard();
    }

    private function demo(string $taxId, string $nombre): SubClient
    {
        $master = MasterClient::create(['name' => 'Cliente '.uniqid()]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => $nombre, 'tax_id' => $taxId]);
    }

    public function test_sin_apply_solo_muestra_lo_que_cambiaria(): void
    {
        $sub = $this->demo('155-9001-1-2026', '[DEMO] Andina Residencial S.A.');

        $this->artisan('qa:fix-demo-fiscal-data')->expectsOutputToContain('Dry-run')->assertExitCode(0);

        $this->assertSame('155-9001-1-2026', $sub->fresh()->tax_id);
    }

    public function test_con_apply_actualiza_ruc_dv_y_razon_social_y_no_toca_otros_clientes(): void
    {
        $demo  = $this->demo('155-9002-1-2026', '[DEMO] Punta Pacífica Torres S.A.');
        $otro  = $this->demo('1689571-1-684168', 'CONSTRUCTORA GRUPO V, SA');

        $this->artisan('qa:fix-demo-fiscal-data', ['--apply' => true])->assertExitCode(0);

        $fresh = $demo->fresh();
        $this->assertSame(SandboxFiscalFixtures::DIGIFACT['tax_id'], $fresh->tax_id);
        $this->assertSame(SandboxFiscalFixtures::DIGIFACT['tax_id_dv'], $fresh->tax_id_dv);
        $this->assertSame(SandboxFiscalFixtures::DIGIFACT['business_name'], $fresh->business_name);
        $this->assertSame('CONSTRUCTORA GRUPO V, SA', $otro->fresh()->business_name);
    }

    public function test_no_reescribe_un_demo_con_documentos_fiscales_certificados(): void
    {
        ['root' => $root, 'card' => $card] = $this->makePedido([['desc' => 'Lámpara', 'qty' => 2, 'price' => 100.0]], [[2]]);
        $sub = SubClient::findOrFail($card->sub_client_id);
        $sub->forceFill(['tax_id' => '155-9001-1-2026'])->save();
        $this->facturar($this->token(), [$root->id])->assertStatus(201);
        $this->reTenant();
        AdminContInvoice::query()->update(['fe_estado' => 'certificado']);

        $this->artisan('qa:fix-demo-fiscal-data', ['--apply' => true])->expectsOutputToContain('se omite')->assertExitCode(0);

        $this->assertSame('155-9001-1-2026', $sub->fresh()->tax_id);
    }

    public function test_no_corre_en_produccion(): void
    {
        $sub = $this->demo('155-4090-1-2026', '[SCRUM-409] Torre Bahía Residencial S.A.');
        $this->app['env'] = 'production';

        $this->artisan('qa:fix-demo-fiscal-data', ['--apply' => true])->assertExitCode(1);

        $this->assertSame('155-4090-1-2026', $sub->fresh()->tax_id);
    }
}
