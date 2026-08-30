<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Compras\Models\Provider;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Épica SCRUM-749 — pipeline del comando `legacy:fuse-proveedores`. Sin Extractor/fake de por medio
 * — nunca toca ICG, solo `legacy.proveedores`/`legacy.producto_costos` y `compras.providers`.
 */
final class LegacyFuseProveedoresCommandTest extends FeatureTestCase
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
        $proveedor = Proveedor::query()->create(['icg_codproveedor' => 4001, 'nombre' => 'Proveedor 4001']);
        Producto::query()->create(['icg_codarticulo' => 1, 'descripcion' => 'Articulo 1']);
        ProductoCosto::query()->create(['icg_codarticulo' => 1, 'codproveedor' => 4001, 'costo_neto' => 5.0]);

        $this->artisan('legacy:fuse-proveedores', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('proveedores_fusion', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);

        $proveedor->refresh();
        $this->assertSame(Proveedor::MATCH_PROMOTED, $proveedor->match_status);
    }

    public function test_dry_run_no_escribe_nada_ni_registra_sync_run(): void
    {
        // Corregido 2026-08-26, mismo fix y mismo motivo que LegacyFuseClientesCommandTest — ver
        // memory/project_scrum755_758_tarea6_procedimiento_real.md.
        $providersAntes = Provider::query()->count();

        $proveedor = Proveedor::query()->create(['icg_codproveedor' => 4002, 'nombre' => 'Proveedor 4002']);
        Producto::query()->create(['icg_codarticulo' => 2, 'descripcion' => 'Articulo 2']);
        ProductoCosto::query()->create(['icg_codarticulo' => 2, 'codproveedor' => 4002, 'costo_neto' => 5.0]);

        $this->artisan('legacy:fuse-proveedores', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
        $proveedor->refresh();
        $this->assertSame(Proveedor::MATCH_UNMATCHED, $proveedor->match_status);
        $this->assertSame($providersAntes, Provider::query()->count());
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->artisan('legacy:fuse-proveedores', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }
}
