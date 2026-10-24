<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\PacAmbienteNoPermitidoException;
use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — cubre la resolución de credenciales sandbox/producción por `fiscal_settings.
 * pac_ambiente` y el guard que bloquea `production` fuera de un APP_ENV realmente productivo
 * (recomendación de Cybersecurity aceptada por Luis 2026-09-24, ver comentario en SCRUM-943).
 */
final class PacProviderFactoryTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        config([
            'services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'prod-key'],
        ]);
    }

    private function actualizarFiscalSettings(string $provider, string $ambiente): void
    {
        AdminContFiscalSettings::current()->forceFill([
            'pac_provider' => $provider,
            'pac_ambiente' => $ambiente,
        ])->save();
    }

    public function test_resuelve_msl_sandbox_cuando_ambiente_es_testing(): void
    {
        $this->actualizarFiscalSettings('msl', 'testing');
        Http::fake(['*' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...'], 200)]);

        $adapter = (new PacProviderFactory())->make();
        $this->assertInstanceOf(MslPacAdapter::class, $adapter);

        $adapter->consultarEstado('ILU', '1001');

        Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://sandbox.msl.test'));
    }

    public function test_resuelve_msl_produccion_cuando_app_env_es_realmente_production(): void
    {
        $this->actualizarFiscalSettings('msl', 'production');
        Http::fake(['*' => Http::response(['estado' => 'CERTIFICADO', 'cufe' => 'FE01...'], 200)]);

        $envOriginal = app()->environment();
        app()['env'] = 'production';

        try {
            $adapter = (new PacProviderFactory())->make();
            $adapter->consultarEstado('ILU', '1001');

            Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://prod.msl.test'));
        } finally {
            app()['env'] = $envOriginal;
        }
    }

    /**
     * La suite de tests corre siempre en APP_ENV=testing — ejercita el guard tal cual correría en
     * dev/qa reales, sin necesidad de mockear el entorno.
     */
    public function test_bloquea_produccion_fuera_de_un_app_env_productivo(): void
    {
        $this->actualizarFiscalSettings('msl', 'production');

        $this->expectException(PacAmbienteNoPermitidoException::class);

        (new PacProviderFactory())->make();
    }

    /** Default de la migración — 'digifact' nunca se wirea (SCRUM-941: cliente eligió MSL). */
    public function test_rechaza_un_pac_provider_no_wireado(): void
    {
        $this->actualizarFiscalSettings('digifact', 'testing');

        $this->expectException(\RuntimeException::class);

        (new PacProviderFactory())->make();
    }
}
