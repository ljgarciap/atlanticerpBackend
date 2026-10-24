<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Console\Commands\LegacyBackfillStockBodegaCentralCommand;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1016 — `legacy:backfill-stock-bodega-central` sobre la BD REAL de pruebas. */
final class LegacyBackfillStockBodegaCentralTest extends FeatureTestCase
{
    private int $cod = 880000;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyBackfillStockBodegaCentralCommand::$antesDeInsertar = null;
        LegacyBackfillStockBodegaCentralCommand::$antesDeVerificar = null;
        $this->tenant();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function central(): int
    {
        return (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');
    }

    private function otra(): int
    {
        return (int) Warehouse::query()->where('name', 'Showroom Obarrio')->value('id');
    }

    /** Producto del catálogo, LIGADO a ICG (legacy.productos.catalog_product_id) salvo `$icg = false`. */
    private function producto(?int $stock, bool $icg = true, bool $activo = true, bool $descatalogado = false): int
    {
        $p = CatalogProduct::create(['reference' => 'BF-' . ++$this->cod, 'name' => 'Producto ' . $this->cod, 'price_full' => 1.0, 'is_active' => $activo, 'stock_quantity' => $stock]);
        if ($icg) {
            Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG ' . $this->cod, 'descatalogado' => $descatalogado, 'catalog_product_id' => $p->id]);
        }

        return (int) $p->id;
    }

    /** @param array<string, mixed> $opciones */
    private function correr(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('legacy:backfill-stock-bodega-central', ['--tenant' => 'atlantic'] + $opciones);
    }

    private function filas(int $productId): array
    {
        return ProductWarehouseStock::query()->where('catalog_product_id', $productId)->orderBy('warehouse_id')->get(['warehouse_id', 'quantity'])->map(fn ($r): array => [(int) $r->warehouse_id, (int) $r->quantity])->all();
    }

    /** @return array<string, string> */
    private function foto(): array
    {
        return [
            'stock' => DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson(),
            'cp'    => DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson(),
            'audit' => (string) AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL)->count(),
        ];
    }

    public function test_siembra_positivos_y_negativos_tal_cual_y_omite_lo_demas(): void
    {
        $pos = $this->producto(12);
        $neg = $this->producto(-7);
        $cero = $this->producto(0);
        $nulo = $this->producto(null);
        $enOtra = $this->producto(5);
        ProductWarehouseStock::create(['catalog_product_id' => $enOtra, 'warehouse_id' => $this->otra(), 'quantity' => 2]);
        $enCentral = $this->producto(9);
        ProductWarehouseStock::create(['catalog_product_id' => $enCentral, 'warehouse_id' => $this->central(), 'quantity' => 4]);
        $sinIcg = $this->producto(30, icg: false);
        $stockQuantityAntes = CatalogProduct::query()->orderBy('id')->pluck('stock_quantity', 'id')->all();

        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(0);
        $this->tenant();

        $this->assertSame([[$this->central(), 12]], $this->filas($pos));
        $this->assertSame([[$this->central(), -7]], $this->filas($neg), 'los negativos se reflejan tal cual');
        $this->assertSame([], $this->filas($cero));
        $this->assertSame([], $this->filas($nulo));
        $this->assertSame([[$this->otra(), 2]], $this->filas($enOtra), 'con fila en otra bodega no se toca ni se suma');
        $this->assertSame([[$this->central(), 4]], $this->filas($enCentral), 'con fila en Central no se pisa');
        $this->assertSame([], $this->filas($sinIcg), 'sin vínculo ICG (p. ej. Lafayette) jamás');
        $this->assertSame($stockQuantityAntes, CatalogProduct::query()->orderBy('id')->pluck('stock_quantity', 'id')->all(), 'ningún stock_quantity cambia');

        $m = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL)->firstOrFail()->metadata;
        $this->assertSame(2, $m['filas_sembradas']);
        $this->assertSame(12, $m['unidades_positivas']);
        $this->assertSame(-7, $m['unidades_negativas']);
        $this->assertSame(1, $m['productos_negativos']);
        $this->assertSame('tests', $m['operador']);
        $this->assertSame(2, $m['omitidos_con_fila']);
    }

    public function test_es_idempotente_la_segunda_corrida_no_cambia_nada(): void
    {
        $this->producto(3);
        $this->producto(-1);

        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(0);
        $this->tenant();
        $despues = $this->foto();

        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('Nada que sembrar')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($despues, $this->foto());
    }

    public function test_el_dry_run_es_el_defecto_reporta_y_no_escribe_nada(): void
    {
        $this->producto(10);
        $this->producto(-4);
        $antes = $this->foto();

        $this->correr()->expectsOutputToContain('Modo dry-run')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_apply_exige_operador(): void
    {
        $this->producto(10);
        $antes = $this->foto();

        $this->correr(['--apply' => true])->expectsOutputToContain('--operador es obligatorio')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_aborta_si_bodega_central_es_solo_consulta_o_no_existe(): void
    {
        $this->producto(10);
        $antes = $this->foto();

        DB::table('atlantic_bodega.warehouses')->where('id', $this->central())->update(['solo_consulta' => true]);
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('es solo_consulta')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        DB::table('atlantic_bodega.warehouses')->where('id', $this->central())->update(['solo_consulta' => false, 'name' => 'Bodega Renombrada']);
        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('UNA bodega "Bodega Central"')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());
    }

    public function test_si_la_verificacion_falla_hay_rollback_completo(): void
    {
        $this->producto(10);
        $this->producto(20);
        $antes = $this->foto();
        $stock = (new ProductWarehouseStock())->getTable();

        // Un efecto colateral: alguien duplica la cantidad al insertar => la Σ de las filas nuevas no coincide con la Σ de stock_quantity.
        DB::statement("CREATE OR REPLACE FUNCTION public.test_scrum1016_muta() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.quantity := NEW.quantity + 1; RETURN NEW; END \$\$");
        DB::statement("CREATE TRIGGER test_scrum1016_muta BEFORE INSERT ON {$stock} FOR EACH ROW EXECUTE FUNCTION public.test_scrum1016_muta()");

        try {
            $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('verificación fallida')->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS test_scrum1016_muta ON {$stock}");
            DB::statement('DROP FUNCTION IF EXISTS public.test_scrum1016_muta()');
        }

        $this->assertSame($antes, $this->foto(), 'ROLLBACK completo, sin evento de auditoría');
    }

    public function test_siembra_por_lotes_chicos(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $this->producto($i);
        }

        $this->correr(['--apply' => true, '--operador' => 'tests', '--lote' => 3])->assertExitCode(0);
        $this->tenant();

        foreach ($ids as $i => $id) {
            $this->assertSame([[$this->central(), $i + 1]], $this->filas($id));
        }
    }

    // ── Ronda 2 (Senior Review) ──────────────────────────────────────────────────────────────────────────────

    public function test_excluye_inactivos_y_descatalogados_y_el_dry_run_los_cuenta_por_separado(): void
    {
        $inactivo = $this->producto(10, activo: false);
        $descatalogado = $this->producto(20, descatalogado: true);
        $ok = $this->producto(5);

        $this->correr()
            ->expectsOutputToContain('INACTIVOS (is_active = false) — no se siembran')
            ->assertExitCode(0);
        $this->tenant();

        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(0);
        $this->tenant();

        $this->assertSame([], $this->filas($inactivo));
        $this->assertSame([], $this->filas($descatalogado));
        $this->assertSame([[$this->central(), 5]], $this->filas($ok));
        $m = AuditLog::query()->where('action', AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL)->firstOrFail()->metadata;
        $this->assertSame(1, $m['omitidos_inactivos']);
        $this->assertSame(1, $m['omitidos_descatalogados']);
        $this->assertSame([$ok], $m['ids_sembrados']);
    }

    public function test_un_producto_con_fila_en_central_en_cero_se_omite(): void
    {
        $id = $this->producto(9);
        ProductWarehouseStock::create(['catalog_product_id' => $id, 'warehouse_id' => $this->central(), 'quantity' => 0]);

        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('Nada que sembrar')->assertExitCode(0);
        $this->tenant();

        $this->assertSame([[$this->central(), 0]], $this->filas($id));
    }

    public function test_productos_de_lafayette_o_promovidos_sin_vinculo_icg_no_se_siembran_aunque_tengan_stock(): void
    {
        $laf = $this->producto(10, icg: false);
        DB::table('atlantic_ventas_diseno.catalog_products')->where('id', $laf)->update(['external_source' => 'lafayette_lighting', 'external_synced_at' => now()]);
        $promovido = $this->producto(12, icg: false); // SCRUM-990: ex-puro de Lafayette promovido (sin marca, sin vínculo ICG)
        $icg = $this->producto(3);

        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(0);
        $this->tenant();

        $this->assertSame([], $this->filas($laf));
        $this->assertSame([], $this->filas($promovido));
        $this->assertSame([[$this->central(), 3]], $this->filas($icg));
    }

    public function test_un_movimiento_de_bodega_sobre_otro_producto_durante_la_corrida_no_aborta(): void
    {
        $sel = $this->producto(10);
        $otro = $this->producto(null, icg: false);
        LegacyBackfillStockBodegaCentralCommand::$antesDeVerificar = function () use ($otro): void {
            // Actividad normal de Bodega sobre un producto NO seleccionado, en la misma ventana.
            ProductWarehouseStock::create(['catalog_product_id' => $otro, 'warehouse_id' => $this->central(), 'quantity' => 3]);
            DB::table('atlantic_ventas_diseno.catalog_products')->where('id', $otro)->update(['stock_quantity' => 3]);
        };

        $this->correr(['--apply' => true, '--operador' => 'tests'])->assertExitCode(0);
        $this->tenant();

        $this->assertSame([[$this->central(), 10]], $this->filas($sel));
    }

    public function test_alterar_un_producto_seleccionado_durante_la_corrida_si_aborta_con_rollback(): void
    {
        $sel = $this->producto(10);
        $antes = $this->foto();
        LegacyBackfillStockBodegaCentralCommand::$antesDeVerificar = function () use ($sel): void {
            DB::table('atlantic_ventas_diseno.catalog_products')->where('id', $sel)->update(['stock_quantity' => 11]);
        };

        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('verificación fallida')->assertExitCode(1);
        $this->tenant();
        LegacyBackfillStockBodegaCentralCommand::$antesDeVerificar = null;

        $this->assertSame($antes, $this->foto());
    }

    public function test_una_fila_que_aparece_entre_la_seleccion_y_el_insert_aborta_porque_el_insert_repite_los_predicados(): void
    {
        $a = $this->producto(10);
        $b = $this->producto(20);
        LegacyBackfillStockBodegaCentralCommand::$antesDeInsertar = function () use ($b): void {
            ProductWarehouseStock::create(['catalog_product_id' => $b, 'warehouse_id' => $this->otra(), 'quantity' => 1]);
        };
        $antes = $this->foto();

        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('el lote insertó 1 de 2')->assertExitCode(1);
        $this->tenant();
        LegacyBackfillStockBodegaCentralCommand::$antesDeInsertar = null;

        $this->assertSame($antes['cp'], $this->foto()['cp']);
        $this->assertSame([], $this->filas($a), 'rollback: el INSERT parcial no queda');
    }

    public function test_si_central_pasa_a_solo_consulta_tras_resolverla_el_trigger_aborta_el_insert_en_bloque(): void
    {
        $this->producto(10);
        $antes = $this->foto();
        LegacyBackfillStockBodegaCentralCommand::$antesDeInsertar = function (): void {
            DB::table('atlantic_bodega.warehouses')->where('id', $this->central())->update(['solo_consulta' => true]);
        };

        try {
            $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('ROLLBACK')->assertExitCode(1);
            $this->tenant();
        } finally {
            LegacyBackfillStockBodegaCentralCommand::$antesDeInsertar = null;
        }

        $this->assertSame($antes['stock'], $this->foto()['stock']);
        $this->assertTrue((bool) DB::table('atlantic_bodega.warehouses')->where('id', $this->central())->value('solo_consulta') === false, 'el rollback revierte también el cambio hecho dentro de la transacción');
    }

    public function test_el_dry_run_avisa_de_los_omitidos_cuyo_saldo_por_bodega_no_cuadra(): void
    {
        $id = $this->producto(10);
        ProductWarehouseStock::create(['catalog_product_id' => $id, 'warehouse_id' => $this->otra(), 'quantity' => 4]); // 4 por bodega vs 10 en stock_quantity

        $this->correr()->expectsOutputToContain('NO cuadra con stock_quantity (diferencia total 6 u.)')->assertExitCode(0);
    }

    public function test_avisa_sin_bloquear_si_central_excluye_disponible(): void
    {
        $id = $this->producto(10);
        DB::table('atlantic_bodega.warehouses')->where('id', $this->central())->update(['excluye_disponible' => true]);

        $this->correr(['--apply' => true, '--operador' => 'tests'])->expectsOutputToContain('excluye_disponible')->assertExitCode(0);
        $this->tenant();

        $this->assertSame([[$this->central(), 10]], $this->filas($id));
    }
}
