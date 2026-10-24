<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Services\DigifactTokenService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Extiende `Tests\TestCase` (no `Tests\Feature\FeatureTestCase`) a propósito — no necesita DB ni
 * tenant, solo Http::fake()/Cache (CACHE_STORE=array en phpunit.xml, ver phpunit.xml). Vive en
 * tests/Feature/ por convención de carpeta con el resto de FacturacionElectronica, pero es
 * liviano — confirmar con Senior Review si conviene reubicarlo.
 */
final class DigifactTokenServiceTest extends TestCase
{
    public function test_obtiene_y_cachea_el_token(): void
    {
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 'jwt-de-prueba'], 200),
        ]);

        config([
            'services.digifact.base_url_pruebas' => 'https://testnucpa.digifact.com/api',
            'services.digifact.ruc' => '155704849-2-2021',
            'services.digifact.username' => 'digifact-test-user',
            'services.digifact.password' => 'digifact-test-pass',
        ]);

        $service = new DigifactTokenService();

        $this->assertSame('jwt-de-prueba', $service->token('pruebas'));
        $this->assertSame('jwt-de-prueba', $service->token('pruebas'));

        Http::assertSentCount(1); // segunda llamada sirve desde cache, no repite el POST
    }

    public function test_forget_fuerza_un_nuevo_token(): void
    {
        Http::fake([
            '*/login/get_token' => Http::sequence()
                ->push(['token' => 'jwt-1'], 200)
                ->push(['token' => 'jwt-2'], 200),
        ]);

        config([
            'services.digifact.base_url_pruebas' => 'https://testnucpa.digifact.com/api',
            'services.digifact.ruc' => '155704849-2-2021',
            'services.digifact.username' => 'digifact-test-user',
            'services.digifact.password' => 'digifact-test-pass',
        ]);

        $service = new DigifactTokenService();

        $this->assertSame('jwt-1', $service->token('pruebas'));
        $service->forget('pruebas');
        $this->assertSame('jwt-2', $service->token('pruebas'));
    }
}
