<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ClientsRevalidateTaxIdsCommand;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1005 — revalidación masiva (solo lectura) de RUC/DV de SubClientes contra la DGI vía MSL, con reporte CSV.
 */
final class ClientsRevalidateTaxIdsCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        Storage::fake('local');
        $this->limpiarCircuito();
    }

    protected function tearDown(): void
    {
        $this->limpiarCircuito();
        parent::tearDown();
    }

    private function limpiarCircuito(): void
    {
        Redis::del('cb:msl_lookup_pruebas:failures', 'cb:msl_lookup_pruebas:opened_at');
    }

    private function sub(string $razon, string $ruc, ?string $dv): SubClient
    {
        $master = MasterClient::create(['name' => 'Cliente '.uniqid()]);

        return SubClient::create(['master_client_id' => $master->id, 'business_name' => $razon, 'tax_id' => $ruc, 'tax_id_dv' => $dv]);
    }

    /** @param array<string, array{0: array<string, mixed>, 1?: int}> $porRuc */
    private function fakeMsl(array $porRuc): void
    {
        Http::fake(['sandbox.msl.test/v1/validar-ruc*' => function (Request $request) use ($porRuc) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            [$body, $status] = $porRuc[$q['ruc']] + [1 => 200];

            return Http::response($body, $status);
        }]);
    }

    /** @return list<array<string, string>> filas del CSV indexadas por columna */
    private function reporte(): array
    {
        $archivos = Storage::disk('local')->allFiles('clients/revalidate-tax-ids');
        $this->assertCount(1, $archivos, 'debe generarse exactamente un reporte');
        $contenido = Storage::disk('local')->get($archivos[0]) ?? '';
        // BOM UTF-8 para que Excel lea bien las tildes; se quita antes de parsear para que no contamine la 1.ª columna.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'el CSV debe empezar con BOM UTF-8');
        $contenido = substr($contenido, 3);
        // Sin array_filter: una línea en blanco entre filas (bug de Storage::append) debe hacer fallar el test.
        $this->assertStringNotContainsString("\n\n", $contenido, 'el CSV no debe tener líneas en blanco');
        $this->assertStringEndsWith("\n", $contenido);
        $lineas = array_map('str_getcsv', explode("\n", rtrim($contenido, "\n")));
        $cabecera = array_shift($lineas);

        return array_map(static fn (array $l): array => array_combine($cabecera, $l), $lineas);
    }

    /** @return array<string, mixed> */
    private function valido(string $nombre, string $dv, string $estado = 'A'): array
    {
        return ['ok' => true, 'valido' => true, 'nombre' => $nombre, 'dv' => $dv, 'tipoRuc' => '2', 'estado' => $estado];
    }

    public function test_reporte_distingue_cada_tipo_de_discrepancia(): void
    {
        $ok = $this->sub('Acme Soluciones, S.A.', '155-0000-1-2026', '32');
        $this->sub('Dv Malo S.A.', '155-0000-2-2026', '99');
        $this->sub('Nombre Viejo S.A.', '155-0000-3-2026', '45');
        $this->sub('Inactivo S.A.', '155-0000-4-2026', '10');
        $this->sub('No Existe S.A.', '155-0000-5-2026', '11');
        $this->sub('Se Cayo S.A.', '155-0000-6-2026', '12');
        $this->sub('Sin Dv S.A.', '155-0000-7-2026', null);
        $this->fakeMsl([
            '155-0000-1-2026' => [$this->valido('ACMÉ SOLUCIONES, SOCIEDAD ANÓNIMA', '32')],
            '155-0000-2-2026' => [$this->valido('DV MALO S.A.', '01')],
            '155-0000-3-2026' => [$this->valido('NOMBRE OFICIAL NUEVO, S.A.', '45')],
            '155-0000-4-2026' => [$this->valido('INACTIVO S.A.', '10', 'I')],
            '155-0000-5-2026' => [['ok' => true, 'valido' => false]],
            '155-0000-6-2026' => [['ok' => false], 503],
            '155-0000-7-2026' => [$this->valido('SIN DV S.A.', '77')],
        ]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $ok->id])->assertExitCode(0);

        $filas = collect($this->reporte())->keyBy('ruc_cargado');
        $this->assertSame('ninguna', $filas['155-0000-1-2026']['discrepancias'], 'tildes y "SOCIEDAD ANÓNIMA" vs "S.A." no son discrepancia');
        $this->assertSame('dv_distinto', $filas['155-0000-2-2026']['discrepancias']);
        $this->assertSame('razon_social_distinta', $filas['155-0000-3-2026']['discrepancias']);
        // El estado "I" del contribuyente se reporta en su columna pero NO es una discrepancia (MSL lo devuelve para todo RUC válido).
        $this->assertSame('ninguna', $filas['155-0000-4-2026']['discrepancias']);
        $this->assertSame('I', $filas['155-0000-4-2026']['estado_contribuyente']);
        $this->assertSame('ruc_inexistente', $filas['155-0000-5-2026']['discrepancias']);
        $this->assertSame('invalido', $filas['155-0000-5-2026']['resultado']);
        $this->assertSame('consulta_no_disponible', $filas['155-0000-6-2026']['discrepancias']);
        $this->assertSame('no_disponible', $filas['155-0000-6-2026']['resultado']);
        $this->assertSame('dv_faltante', $filas['155-0000-7-2026']['discrepancias']);
        $this->assertSame('77', $filas['155-0000-7-2026']['dv_oficial']);
    }

    public function test_no_escribe_en_ninguna_tabla_de_negocio(): void
    {
        $primero = $this->sub('Acme S.A.', '155-0000-1-2026', '32');
        $this->sub('Otro S.A.', '155-0000-2-2026', '99');
        $this->fakeMsl([
            '155-0000-1-2026' => [$this->valido('OTRO NOMBRE S.A.', '01')],
            '155-0000-2-2026' => [['ok' => true, 'valido' => false]],
        ]);
        $antes = $this->huella();

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $primero->id])->assertExitCode(0);

        $this->assertSame($antes, $this->huella(), 'el comando de solo lectura no debe cambiar ninguna fila de negocio ni de auditoría');
    }

    /** @return array<string, string> md5 del contenido de las tablas que el comando podría llegar a tocar */
    private function huella(): array
    {
        $out = [];
        foreach (['atlantic_ventas_diseno.sub_clients', 'atlantic_ventas_diseno.master_clients', 'atlantic_auth.audit_logs', 'atlantic_admin_contab.fe_transmisiones'] as $tabla) {
            $out[$tabla] = md5(json_encode(DB::table($tabla)->orderBy('id')->get()) ?: '');
        }

        return $out;
    }

    public function test_se_niega_a_correr_sin_dry_run(): void
    {
        Http::fake();
        $this->sub('Acme S.A.', '155-0000-1-2026', '32');

        $this->artisan('clients:revalidate-tax-ids', [])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles('clients'));
    }

    public function test_con_la_fe_apagada_no_llama_a_msl_y_termina_con_mensaje_claro(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        Http::fake();
        $this->sub('Acme S.A.', '155-0000-1-2026', '32');

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0])
            ->expectsOutputToContain('APAGADA')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles('clients'));
    }

    public function test_una_consulta_no_disponible_no_aborta_la_corrida(): void
    {
        $a = $this->sub('Uno S.A.', '155-0000-1-2026', '32');
        $this->sub('Dos S.A.', '155-0000-2-2026', '33');
        $this->fakeMsl([
            '155-0000-1-2026' => [['ok' => false], 503],
            '155-0000-2-2026' => [$this->valido('DOS S.A.', '33')],
        ]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $a->id])->assertExitCode(0);

        $filas = collect($this->reporte())->keyBy('ruc_cargado');
        $this->assertSame('no_disponible', $filas['155-0000-1-2026']['resultado']);
        $this->assertSame('valido', $filas['155-0000-2-2026']['resultado']);
    }

    public function test_from_id_reanuda_sin_repetir_los_anteriores(): void
    {
        $this->sub('Uno S.A.', '155-0000-1-2026', '32');
        $b = $this->sub('Dos S.A.', '155-0000-2-2026', '33');
        $this->fakeMsl(['155-0000-2-2026' => [$this->valido('DOS S.A.', '33')]]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $b->id])->assertExitCode(0);

        $rucs = array_column($this->reporte(), 'ruc_cargado');
        $this->assertContains('155-0000-2-2026', $rucs);
        $this->assertNotContains('155-0000-1-2026', $rucs);
    }

    public function test_una_razon_social_que_parece_formula_no_se_ejecuta_en_excel(): void
    {
        $a = $this->sub('=HYPERLINK("http://x","clic")', '155-0000-1-2026', '32');
        $this->fakeMsl(['155-0000-1-2026' => [$this->valido('OTRO', '32')]]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $a->id])->assertExitCode(0);

        $this->assertStringStartsWith("'=", $this->reporte()[0]['razon_social_cargada']);
    }

    public function test_normalizacion_de_razon_social(): void
    {
        $this->assertSame(
            ClientsRevalidateTaxIdsCommand::normalizarNombre('Acmé Soluciones, S.A.'),
            ClientsRevalidateTaxIdsCommand::normalizarNombre('ACME SOLUCIONES, SOCIEDAD ANÓNIMA'),
        );
        $this->assertNotSame(
            ClientsRevalidateTaxIdsCommand::normalizarNombre('Salas S.A.'),
            ClientsRevalidateTaxIdsCommand::normalizarNombre('Sal S.A.'),
        );
    }

    public function test_un_tax_id_vacio_no_se_manda_a_msl_y_se_reporta_como_sin_ruc(): void
    {
        $vacio = $this->sub('Sin Ruc S.A.', '', null);
        $conRuc = $this->sub('Con Ruc S.A.', '155-0000-2-2026', '33');
        $this->fakeMsl(['155-0000-2-2026' => [$this->valido('CON RUC S.A.', '33')]]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $vacio->id])->assertExitCode(0);

        $filas = collect($this->reporte())->keyBy('id');
        $this->assertSame('sin_ruc', $filas[(string) $vacio->id]['discrepancias']);
        $this->assertSame('valido', $filas[(string) $conRuc->id]['resultado']);
        Http::assertSentCount(1);
    }

    public function test_si_se_apaga_la_fe_a_mitad_de_corrida_se_detiene_y_sale_con_error(): void
    {
        $a = $this->sub('Uno S.A.', '155-0000-1-2026', '32');
        $this->sub('Dos S.A.', '155-0000-2-2026', '33');
        $this->sub('Tres S.A.', '155-0000-3-2026', '34');
        Http::fake(['sandbox.msl.test/v1/validar-ruc*' => function () {
            // Después de la primera consulta alguien apaga la FE desde Configuración Fiscal.
            AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();

            return Http::response($this->valido('UNO S.A.', '32'), 200);
        }]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $a->id])
            ->expectsOutputToContain('--from-id=')
            ->assertExitCode(1);

        $this->assertCount(2, $this->reporte(), 'la 1.ª fila consultada y la que detectó el apagado; la 3.ª ni se intentó');
        Http::assertSentCount(1);
    }

    public function test_avisa_si_muchas_consultas_no_estuvieron_disponibles(): void
    {
        $a = $this->sub('Uno S.A.', '155-0000-1-2026', '32');
        $this->sub('Dos S.A.', '155-0000-2-2026', '33');
        $this->fakeMsl(['155-0000-1-2026' => [['ok' => false], 503], '155-0000-2-2026' => [['ok' => false], 503]]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $a->id])
            ->expectsOutputToContain('no estuvieron disponibles')
            ->assertExitCode(0);
    }

    public function test_normalizacion_cubre_formas_societarias_comunes(): void
    {
        foreach ([
            ['Acme S. de R.L.', 'ACME, SOCIEDAD DE RESPONSABILIDAD LIMITADA'],
            ['Acme Corp.', 'ACME CORPORACIÓN'],
            ['Acme S.A.', 'ACME, S. A.'],
        ] as [$cargada, $oficial]) {
            $this->assertSame(
                ClientsRevalidateTaxIdsCommand::normalizarNombre($cargada),
                ClientsRevalidateTaxIdsCommand::normalizarNombre($oficial),
                "{$cargada} vs {$oficial}",
            );
        }
    }

    public function test_los_consumidor_final_no_se_consultan_ni_se_reportan_como_hallazgo(): void
    {
        $cf = $this->sub('Juan Pérez', '', null);
        $cf->update(['receptor_tipo' => '02']);
        $conRuc = $this->sub('Con Ruc S.A.', '155-0000-2-2026', '33');
        $this->fakeMsl(['155-0000-2-2026' => [$this->valido('CON RUC S.A.', '33')]]);

        $this->artisan('clients:revalidate-tax-ids', ['--dry-run' => true, '--rate' => 0, '--from-id' => $cf->id])->assertExitCode(0);

        $filas = collect($this->reporte())->keyBy('id');
        $this->assertSame('consumidor_final', $filas[(string) $cf->id]['resultado']);
        $this->assertSame('ninguna', $filas[(string) $cf->id]['discrepancias']);
        $this->assertSame('valido', $filas[(string) $conRuc->id]['resultado']);
        Http::assertSentCount(1); // solo el cliente con RUC
    }
}
