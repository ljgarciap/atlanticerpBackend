<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Épica SCRUM-749 — pipeline del comando `legacy:fuse-clientes`. Sin Extractor/fake de por medio
 * (a diferencia de `legacy:sync-*`) — este comando nunca toca ICG, solo `legacy.clientes` y
 * `ventas_diseno.{master_clients,sub_clients}`, así que los tests siembran directo.
 */
final class LegacyFuseClientesCommandTest extends FeatureTestCase
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

    public function test_corrida_real_promueve_y_registra_sync_run(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 3001, 'razon_social' => 'Cliente 3001']);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 1, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'orphan' => false,
        ]);

        $this->artisan('legacy:fuse-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('clientes_fusion', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_PROMOTED, $cliente->match_status);
    }

    public function test_dry_run_no_escribe_nada_ni_registra_sync_run(): void
    {
        // Corregido 2026-08-26 — hallazgo real en dev (ver
        // memory/project_scrum755_758_tarea6_procedimiento_real.md): antes --dry-run promovía
        // sub_clients/master_clients reales igual que una corrida real, solo se saltaba el
        // registro en legacy.sync_runs. Ahora --dry-run es un rollback real (DryRunAbort) — nada
        // queda escrito, ni en legacy.clientes ni en ventas_diseno.sub_clients/master_clients.
        $subClientesAntes = SubClient::query()->count();

        $cliente = Cliente::query()->create(['icg_codcliente' => 3002, 'razon_social' => 'Cliente 3002']);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 2, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'orphan' => false,
        ]);

        $this->artisan('legacy:fuse-clientes', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
        $this->assertSame($subClientesAntes, SubClient::query()->count());
    }

    public function test_sin_candidatos_registra_sync_run_en_cero(): void
    {
        $this->artisan('legacy:fuse-clientes', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(0, $run->extraidos);
        $this->assertSame(0, $run->cargados);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->artisan('legacy:fuse-clientes', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }
}
