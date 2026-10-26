<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-943 — activa `pac_provider` como dato paramétrico, nunca cambiando el default de columna. */
final class AdminContSetPacProviderCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_activa_msl_para_el_tenant(): void
    {
        $this->assertSame('none', AdminContFiscalSettings::current()->pac_provider);

        $this->artisan('admin-contab:set-pac-provider', ['provider' => 'msl', '--tenant' => 'atlantic'])
            ->assertExitCode(0);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('msl', AdminContFiscalSettings::current()->pac_provider);
    }

    public function test_es_idempotente_si_ya_esta_en_el_valor_pedido(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl'])->save();

        $this->artisan('admin-contab:set-pac-provider', ['provider' => 'msl', '--tenant' => 'atlantic'])
            ->assertExitCode(0);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('msl', AdminContFiscalSettings::current()->pac_provider);
    }

    public function test_rechaza_un_provider_invalido(): void
    {
        $this->artisan('admin-contab:set-pac-provider', ['provider' => 'otro', '--tenant' => 'atlantic'])
            ->assertExitCode(1);
    }

    public function test_rechaza_un_tenant_inexistente(): void
    {
        $this->artisan('admin-contab:set-pac-provider', ['provider' => 'msl', '--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }
}
