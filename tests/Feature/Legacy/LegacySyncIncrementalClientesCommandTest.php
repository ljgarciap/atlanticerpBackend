<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — `legacy:sync-incremental-clientes`: hash-compare + circuit breaker, mismo patrón
 * (y mismo gotcha de testing) que LegacySyncIncrementalProductosCommandTest — ver ese docblock
 * para el porqué de MutableFakeClienteExtractor en vez de rebindear el contenedor a mitad de test.
 */
final class LegacySyncIncrementalClientesCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_corrida_real_registra_origen_scheduler(): void
    {
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('clientes', $run->entidad);
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_re_correr_sin_cambios_no_ejecuta_el_matcher(): void
    {
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $runs = SyncRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $runs->last()->status);
        $this->assertSame(0, $runs->last()->mapeados);
        $this->assertSame(0, $runs->last()->cargados);
    }

    public function test_circuit_breaker_frena_una_corrida_con_volumen_de_cambio_anormal(): void
    {
        $primeraCorrida = new ExtractedClientes(
            clientes: [
                new ClienteRow(1, null, 'Cliente 1', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(2, null, 'Cliente 2', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(3, null, 'Cliente 3', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(4, null, 'Cliente 4', null, null, null, null, null, null, null, null, null, null, false, null),
            ],
            contactos: [],
        );
        $fake = new MutableFakeClienteExtractor($primeraCorrida);
        $this->app->instance(ClienteExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // 3 de 4 cambian (75% > 20% de umbral).
        $segundaCorrida = new ExtractedClientes(
            clientes: [
                new ClienteRow(1, null, 'Cliente 1 (renombrado)', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(2, null, 'Cliente 2 (renombrado)', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(3, null, 'Cliente 3 (renombrado)', null, null, null, null, null, null, null, null, null, null, false, null),
                new ClienteRow(4, null, 'Cliente 4', null, null, null, null, null, null, null, null, null, null, false, null),
            ],
            contactos: [],
        );
        $fake->setResult($segundaCorrida);
        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultimaCorrida = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultimaCorrida->status);
        $this->assertSame(0, $ultimaCorrida->cargados);

        // El staging sí se actualizó (es de solo referencia, seguro de re-hacer) — el circuit
        // breaker frena el Matcher, no el staging.
        $cliente1 = Cliente::query()->where('icg_codcliente', 1)->firstOrFail();
        $this->assertSame('Cliente 1 (renombrado)', $cliente1->razon_social);
        // El Matcher nunca corrió — match_status sigue en el default.
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente1->match_status);
    }

    public function test_circuit_breaker_no_dispara_con_un_cambio_normal(): void
    {
        $primeraCorrida = new ExtractedClientes(
            clientes: array_map(
                static fn (int $i): ClienteRow => new ClienteRow($i, null, "Cliente {$i}", null, null, null, null, null, null, null, null, null, null, false, null),
                range(1, 5),
            ),
            contactos: [],
        );
        $fake = new MutableFakeClienteExtractor($primeraCorrida);
        $this->app->instance(ClienteExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        // Solo 1 de 5 cambia (20%, no supera el umbral > 20%).
        $clientesSegunda = array_map(
            static fn (int $i): ClienteRow => new ClienteRow($i, null, "Cliente {$i}", null, null, null, null, null, null, null, null, null, null, false, null),
            range(1, 5),
        );
        $clientesSegunda[0] = new ClienteRow(1, null, 'Cliente 1 (renombrado)', null, null, null, null, null, null, null, null, null, null, false, null);
        $fake->setResult(new ExtractedClientes(clientes: $clientesSegunda, contactos: []));
        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultimaCorrida = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultimaCorrida->status);
        $this->assertSame(1, $ultimaCorrida->mapeados + $ultimaCorrida->excluidos); // el Matcher SÍ corrió sobre el 1 cambiado
    }

    public function test_corrida_real_persiste_nif20_y_ruc_normalizado(): void
    {
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(new ExtractedClientes(
            clientes: [new ClienteRow(1001, null, 'Cliente Uno', '155783782-2-', null, null, null, null, null, null, null, null, null, false, null, '155783782-2-2026DV35')],
            contactos: [],
        )));

        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('atlantic_legacy.clientes', [
            'icg_codcliente' => 1001, 'nif20' => '155783782-2-2026DV35', 'ruc' => '155783782-2-2026',
            'ruc_dv' => '35', 'ruc_origen' => 'nif20', 'ruc_estado' => 'ok',
        ]);
    }

    public function test_dry_run_nunca_evalua_el_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-incremental-clientes', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    private static function sampleExtraction(): ExtractedClientes
    {
        return new ExtractedClientes(
            clientes: [new ClienteRow(1001, null, 'Cliente Uno', 'CIF1', null, null, null, null, null, null, null, null, null, false, null)],
            contactos: [],
        );
    }
}
