<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\FeatureTestCase;

/** `legacy:backfill-costo-proveedor` sobre la BD REAL de pruebas (caso A691-451471527K: costo solo con MARSET, proveedor 70). */
final class LegacyBackfillCostoProveedorCommandTest extends FeatureTestCase
{
    private int $cod = 870000;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<int, float> $costos codproveedor => costo_neto en producto_costos */
    private function producto(?float $cost, array $costos, bool $activo = true, bool $descatalogado = false): int
    {
        $p = CatalogProduct::create(['reference' => 'CP-' . ++$this->cod, 'name' => 'P ' . $this->cod, 'price_full' => 10.0, 'cost' => $cost, 'is_active' => $activo]);
        Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG ' . $this->cod, 'descatalogado' => $descatalogado, 'catalog_product_id' => $p->id]);
        foreach ($costos as $prov => $neto) {
            ProductoCosto::query()->create(['icg_codarticulo' => $this->cod, 'codproveedor' => $prov, 'costo_neto' => $neto]);
        }

        return (int) $p->id;
    }

    private function compra(int $productoId, int $codproveedor, string $fecha): void
    {
        $cod = (int) Producto::query()->where('catalog_product_id', $productoId)->value('icg_codarticulo');
        $cab = AlbaranCompra::query()->create(['icg_numserie' => 'T', 'icg_numalbaran' => $cod * 10 + $codproveedor, 'icg_n' => 'B', 'icg_codproveedor' => $codproveedor, 'fecha' => $fecha]);
        AlbaranCompraItem::query()->create(['legacy_albaran_compra_id' => $cab->id, 'icg_codarticulo' => $cod, 'precio' => 1.0]);
    }

    private function costo(int $id): ?float
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el comando olvida el tenant al terminar
        $c = CatalogProduct::query()->find($id)?->cost;

        return $c === null ? null : (float) $c;
    }

    /** @param array<string, mixed> $opciones */
    private function correr(array $opciones = []): PendingCommand
    {
        return $this->artisan('legacy:backfill-costo-proveedor', ['--tenant' => 'atlantic'] + $opciones);
    }

    private function huella(): string
    {
        $this->correr()->expectsOutputToContain('DRY-RUN')->assertExitCode(0);
        // La huella se calcula igual que el comando: se obtiene de su salida vía un segundo dry-run capturado.
        \Illuminate\Support\Facades\Artisan::call('legacy:backfill-costo-proveedor', ['--tenant' => 'atlantic']);
        $out = \Illuminate\Support\Facades\Artisan::output();
        preg_match('/--esperado=([0-9a-f]{32})/', $out, $m);
        $this->assertNotEmpty($m, 'el dry-run debe imprimir la huella');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $m[1];
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $id = $this->producto(null, [70 => 480.06]);

        $this->correr()->assertExitCode(0);
        $this->assertNull($this->costo($id));
    }

    public function test_aplica_un_proveedor_y_varios_por_compra_y_respeta_lo_demas(): void
    {
        $unico = $this->producto(null, [70 => 480.06]);
        $varios = $this->producto(null, [70 => 480.0, 38 => 510.0]);
        $this->compra($varios, 70, '2026-01-10 00:00:00');
        $this->compra($varios, 38, '2026-06-10 00:00:00');
        $sinCompras = $this->producto(null, [70 => 1.0, 38 => 2.0]);
        $con1001Nulo = $this->producto(null, [1001 => 50.0, 70 => 60.0]);
        $sinIcg = $this->producto(null, []);
        $conCosto = $this->producto(99.0, [70 => 480.0]);
        $inactivo = $this->producto(null, [70 => 5.0], activo: false);
        $descat = $this->producto(null, [70 => 5.0], descatalogado: true);

        $huella = $this->huella();
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => $huella])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame(480.06, $this->costo($unico));
        $this->assertSame(510.0, $this->costo($varios), 'gana la compra más reciente');
        $this->assertNull($this->costo($sinCompras), 'varios proveedores sin compras no se adivina');
        $this->assertNull($this->costo($con1001Nulo), 'costo 1001 con cost nulo es otra causa: no se toca');
        $this->assertNull($this->costo($sinIcg));
        $this->assertSame(99.0, $this->costo($conCosto), 'nunca pisa un costo existente');
        $this->assertNull($this->costo($inactivo));
        $this->assertNull($this->costo($descat));

        $evento = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR)->firstOrFail();
        $this->assertSame('tests', $evento->metadata['operador']);
        $this->assertSame(2, $evento->metadata['productos']);
        $this->assertEquals([$unico => 480.06, $varios => 510.0], $evento->metadata['costos_aplicados']);
    }

    public function test_es_idempotente(): void
    {
        $id = $this->producto(null, [70 => 480.06]);
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => $this->huella()])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(480.06, $this->costo($id));

        $antes = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR)->count();
        $this->correr()->expectsOutputToContain('Nada que aplicar')->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($antes, AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR)->count());
    }

    public function test_apply_exige_operador_y_huella_correcta(): void
    {
        $id = $this->producto(null, [70 => 480.06]);

        $this->correr(['--apply' => true])->assertExitCode(1);
        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(1);
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => str_repeat('0', 32)])->assertExitCode(1);
        $this->assertNull($this->costo($id));
    }

    public function test_revert_deja_en_null_solo_lo_que_sigue_valiendo_lo_aplicado(): void
    {
        $a = $this->producto(null, [70 => 480.06]);
        $b = $this->producto(null, [38 => 12.5]);
        $this->correr(['--apply' => true, '--operador' => 'tests', '--esperado' => $this->huella()])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // Alguien edita el costo de B a mano después del backfill.
        CatalogProduct::query()->whereKey($b)->update(['cost' => 13.0]);
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR)->firstOrFail();

        $this->correr(['--revert' => $evento->id, '--operador' => 'tests'])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertNull($this->costo($a));
        $this->assertSame(13.0, $this->costo($b), 'el costo editado después no se revierte');
    }
}
