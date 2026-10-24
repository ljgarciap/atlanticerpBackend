<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPedidos;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class LegacySyncIncrementalPedidosCommandTest extends FeatureTestCase
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

    public function test_corrida_real_matchea_contra_clientes_y_registra_origen_scheduler(): void
    {
        Cliente::query()->create(['icg_codcliente' => 2001, 'razon_social' => 'Cliente X']);

        $this->app->instance(PedidoExtractorContract::class, new FakePedidoExtractor(
            new ExtractedPedidos([new PedidoRow('A', 1, 1, 2001, null, null, 500.0, 5, 'P', null, null)], []),
        ));

        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::ORIGEN_SCHEDULER, $run->origen);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->mapeados);
    }

    public function test_re_correr_sin_cambios_no_ejecuta_el_matcher(): void
    {
        Cliente::query()->create(['icg_codcliente' => 2001, 'razon_social' => 'Cliente X']);
        $extracted = new ExtractedPedidos([new PedidoRow('A', 1, 1, 2001, null, null, 500.0, 5, 'P', null, null)], []);

        $this->app->instance(PedidoExtractorContract::class, new FakePedidoExtractor($extracted));
        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
    }

    public function test_circuit_breaker_frena_una_corrida_con_volumen_de_cambio_anormal(): void
    {
        $primera = new ExtractedPedidos([
            new PedidoRow('A', 1, 1, null, null, null, 100.0, null, null, null, null),
            new PedidoRow('A', 2, 1, null, null, null, 100.0, null, null, null, null),
            new PedidoRow('A', 3, 1, null, null, null, 100.0, null, null, null, null),
            new PedidoRow('A', 4, 1, null, null, null, 100.0, null, null, null, null),
        ], []);
        $fake = new MutableFakePedidoExtractor($primera);
        $this->app->instance(PedidoExtractorContract::class, $fake);
        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $segunda = new ExtractedPedidos([
            new PedidoRow('A', 1, 1, null, null, null, 999.0, null, null, null, null),
            new PedidoRow('A', 2, 1, null, null, null, 999.0, null, null, null, null),
            new PedidoRow('A', 3, 1, null, null, null, 999.0, null, null, null, null),
            new PedidoRow('A', 4, 1, null, null, null, 100.0, null, null, null, null),
        ], []);
        $fake->setResult($segunda);
        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $ultima = SyncRun::query()->orderBy('id')->get()->last();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $ultima->status);
        $this->assertSame(0, $ultima->mapeados);
    }

    public function test_dry_run_nunca_evalua_circuit_breaker_ni_registra_corrida(): void
    {
        $this->app->instance(PedidoExtractorContract::class, new FakePedidoExtractor(
            new ExtractedPedidos([new PedidoRow('A', 1, 1, null, null, null, 500.0, null, null, null, null)], []),
        ));

        $this->artisan('legacy:sync-incremental-pedidos', ['--tenant' => 'atlantic', '--dry-run' => true])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }
}
