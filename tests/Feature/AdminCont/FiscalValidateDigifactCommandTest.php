<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Fase 24 (D) — el comando de verificación de Digifact es de solo lectura y nunca toca producción sin permiso explícito. */
final class FiscalValidateDigifactCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function credenciales(): void
    {
        config(['services.digifact.ruc' => '1-1-1', 'services.digifact.username' => 'usuario', 'services.digifact.password' => 'clave']);
    }

    public function test_sin_credenciales_falla_y_no_llama_a_la_red(): void
    {
        Http::fake();
        config(['services.digifact.ruc' => null, 'services.digifact.username' => null, 'services.digifact.password' => null]);

        $this->artisan('fiscal:validate-digifact')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_produccion_exige_confirmacion_explicita(): void
    {
        Http::fake();
        $this->credenciales();

        $this->artisan('fiscal:validate-digifact', ['--ambiente' => 'produccion'])->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_obtiene_el_token_y_no_lo_imprime(): void
    {
        $this->credenciales();
        Http::fake(['*/login/get_token' => Http::response(['token' => 'SECRETO-123'])]);

        $this->artisan('fiscal:validate-digifact')
            ->expectsOutputToContain('Token obtenido')
            ->doesntExpectOutputToContain('SECRETO-123')
            ->assertExitCode(0);
    }

    public function test_consulta_un_ruc_y_reporta_el_resultado(): void
    {
        $this->credenciales();
        Http::fake([
            '*/login/get_token' => Http::response(['token' => 't']),
            '*/GetInfoRuc*' => Http::response(['valido' => true, 'nombre' => 'EMPRESA DE PRUEBA', 'dv' => '12']),
        ]);

        $this->artisan('fiscal:validate-digifact', ['--ruc' => '1-1-1'])
            ->expectsOutputToContain('Receptor válido: sí')
            ->assertExitCode(0);
    }

    public function test_token_rechazado_falla(): void
    {
        $this->credenciales();
        Http::fake(['*/login/get_token' => Http::response([], 401)]);

        $this->artisan('fiscal:validate-digifact')->assertExitCode(1);
    }
}
