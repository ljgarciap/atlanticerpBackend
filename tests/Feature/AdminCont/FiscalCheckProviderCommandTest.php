<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/** Fase 24 (D) — el proveedor fiscal es opcional: sin proveedor o sin credenciales no hay nada que validar, y nunca se emite nada. */
final class FiscalCheckProviderCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function proveedor(string $provider, string $ambiente = 'testing'): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => $provider, 'pac_ambiente' => $ambiente])->save();
    }

    public function test_sin_proveedor_no_hay_nada_que_validar(): void
    {
        Http::fake();
        $this->proveedor('none');

        $this->artisan('fiscal:check-provider')->expectsOutputToContain('Nada que validar')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_proveedor_sin_credenciales_no_es_un_error(): void
    {
        Http::fake();
        config(['services.digifact.ruc' => null, 'services.digifact.username' => null, 'services.digifact.password' => null]);
        $this->proveedor('digifact');

        $this->artisan('fiscal:check-provider')->expectsOutputToContain('no tiene credenciales')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_con_credenciales_y_sin_ruc_no_llama_a_la_red(): void
    {
        Http::fake();
        config(['services.digifact.ruc' => '1', 'services.digifact.username' => 'u', 'services.digifact.password' => 'p']);
        $this->proveedor('digifact');

        $this->artisan('fiscal:check-provider')->expectsOutputToContain('--ruc')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_consulta_el_ruc_por_el_contrato_del_proveedor(): void
    {
        config(['services.digifact.ruc' => '1', 'services.digifact.username' => 'u', 'services.digifact.password' => 'p']);
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'SECRETO']),
            '*/GetInfoRuc*' => Http::response(['valido' => true, 'nombre' => 'EMPRESA DE PRUEBA', 'dv' => '12']),
        ]);
        $this->proveedor('digifact');

        $this->artisan('fiscal:check-provider', ['--ruc' => '1-1-1'])
            ->expectsOutputToContain('Receptor válido: sí')
            ->doesntExpectOutputToContain('SECRETO')
            ->assertExitCode(0);
    }

    public function test_ambiente_de_produccion_exige_confirmacion(): void
    {
        Http::fake();
        config(['services.digifact.ruc' => '1', 'services.digifact.username' => 'u', 'services.digifact.password' => 'p']);
        $this->proveedor('digifact', 'production');

        $this->artisan('fiscal:check-provider', ['--ruc' => '1-1-1'])->assertExitCode(2);

        Http::assertNothingSent();
    }
}
