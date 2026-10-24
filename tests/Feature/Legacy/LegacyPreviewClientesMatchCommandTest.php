<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1137 — `legacy:preview-clientes-match`: informe de solo lectura, sin RUC ni nombres en la salida. */
final class LegacyPreviewClientesMatchCommandTest extends FeatureTestCase
{
    public function test_informa_las_transiciones_sin_escribir_ni_imprimir_el_ruc(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $master = MasterClient::query()->create(['name' => 'Master X']);
        SubClient::query()->create(['master_client_id' => $master->id, 'business_name' => 'Sub X', 'tax_id' => '155716554-2-2021']);
        $cliente = Cliente::query()->create([
            'icg_codcliente' => 2001, 'razon_social' => 'Cliente Secreto SA', 'cif' => '155716554-2',
            'ruc' => '155716554-2-2021', 'ruc_estado' => 'ok', 'bloqueado' => false,
        ]);

        $this->artisan('legacy:preview-clientes-match')
            ->expectsOutputToContain('no se escribió nada')
            ->expectsOutputToContain('unmatched -> matched')
            ->doesntExpectOutputToContain('155716554')
            ->doesntExpectOutputToContain('Cliente Secreto')
            ->assertExitCode(0);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $cliente->refresh();
        $this->assertNull($cliente->sub_client_id);
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->artisan('legacy:preview-clientes-match --tenant=no-existe')->assertExitCode(1);
    }
}
