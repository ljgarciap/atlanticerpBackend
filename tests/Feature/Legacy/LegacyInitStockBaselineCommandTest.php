<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1114 — `legacy:init-stock-baseline` sobre la BD REAL de pruebas. */
final class LegacyInitStockBaselineCommandTest extends FeatureTestCase
{
    private int $cod = 860000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        StockSyncSettings::current()->update(['baseline_inicializada_at' => null]);
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{0: int, 1: int} [catalog_product_id, icg_codarticulo] */
    private function producto(?int $stock, ?float $al4, bool $activo = true, bool $descatalogado = false): array
    {
        $cat = CatalogProduct::create(['reference' => 'IB-' . ++$this->cod, 'name' => 'Producto ' . $this->cod, 'price_full' => 1.0, 'is_active' => $activo, 'stock_quantity' => $stock]);
        Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG ' . $this->cod, 'descatalogado' => $descatalogado, 'catalog_product_id' => $cat->id, 'stock_al4' => $al4]);

        return [(int) $cat->id, $this->cod];
    }

    private function base(int $cod): ?int
    {
        $this->tenant();
        $v = Producto::query()->where('icg_codarticulo', $cod)->value('stock_al4_aplicado');

        return $v === null ? null : (int) $v;
    }

    /** @param array<string, mixed> $opciones */
    private function init(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('legacy:init-stock-baseline', ['--tenant' => 'atlantic'] + $opciones);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        [, $cod] = $this->producto(5, 5.0);

        $this->init()->assertExitCode(0);

        $this->assertNull($this->base($cod));
        $this->tenant();
        $this->assertNull(StockSyncSettings::current()->baseline_inicializada_at);
    }

    public function test_apply_exige_operador(): void
    {
        $this->init(['--apply' => true])->assertExitCode(1);
    }

    public function test_inicializa_la_base_desde_el_staging_con_redondeo_y_cero_si_no_hay_fila_en_al4(): void
    {
        [, $conStock] = $this->producto(13, 12.6);
        [, $sinFila]  = $this->producto(null, null);
        [, $negativo] = $this->producto(-3, -3.0);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame(13, $this->base($conStock));
        $this->assertSame(0, $this->base($sinFila));
        $this->assertSame(-3, $this->base($negativo));
        $this->tenant();
        $this->assertNotNull(StockSyncSettings::current()->baseline_inicializada_at);
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_INIT_STOCK_BASELINE)->firstOrFail();
        $this->assertSame('Prueba', $evento->metadata['operador']);
    }

    public function test_inactivos_y_descatalogados_quedan_sin_base(): void
    {
        [, $inactivo] = $this->producto(5, 5.0, activo: false);
        [, $desc]     = $this->producto(5, 5.0, descatalogado: true);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertNull($this->base($inactivo));
        $this->assertNull($this->base($desc));
    }

    public function test_nunca_pisa_una_base_ya_inicializada(): void
    {
        [, $cod] = $this->producto(5, 5.0);
        Producto::query()->where('icg_codarticulo', $cod)->update(['stock_al4_aplicado' => 77]);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame(77, $this->base($cod));
    }

    public function test_si_el_staging_se_refresco_tras_la_carga_la_base_se_reconstruye_desde_atlanticerp(): void
    {
        $central = (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');
        // Evento de la carga inicial, hace 1 hora.
        $evento = AuditLog::query()->create(['user_id' => null, 'action' => AuditService::LEGACY_BACKFILL_STOCK_BODEGA_CENTRAL, 'entity_type' => 'product_warehouse_stock', 'metadata' => []]);
        DB::table($evento->getTable())->where('id', $evento->id)->update(['created_at' => now()->subHour()]);

        // Se cargaron 5; AtlanticERP vendió 2 (kardex posterior a la carga) -> stock_quantity 3.
        [$cat, $cod] = $this->producto(5, 5.0);
        ProductWarehouseStock::create(['catalog_product_id' => $cat, 'warehouse_id' => $central, 'quantity' => 5]);
        app(KardexService::class)->registrar($cat, $central, InventoryMovement::TIPO_SALIDA, -2, 'venta AtlanticERP');
        // El incremental refrescó stock_al4 DESPUÉS de la carga con un valor de ICG más nuevo (9): no sirve como base.
        DB::table((new Producto())->getTable())->where('icg_codarticulo', $cod)->update(['stock_al4' => 9, 'updated_at' => now()->subMinutes(30)]);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertSame(5, $this->base($cod)); // stock_quantity 3 - (-2) = 5, lo que se cargó
    }

    public function test_se_niega_a_aplicar_si_la_base_no_cuadra_con_stock_quantity_salvo_force(): void
    {
        // Staging dice 5 pero AtlanticERP tiene 8 y ningún movimiento que lo explique.
        [, $cod] = $this->producto(8, 5.0);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(1);
        $this->assertNull($this->base($cod));
        $this->tenant();
        $this->assertNull(StockSyncSettings::current()->baseline_inicializada_at);

        $this->init(['--apply' => true, '--operador' => 'Prueba', '--force' => true])->assertExitCode(0);
        $this->assertSame(5, $this->base($cod));
    }

    public function test_un_producto_que_icg_tenia_con_stock_pero_atlanticerp_nunca_recibio_queda_sin_base_para_sembrarse(): void
    {
        // Estuvo inactivo/descatalogado en la carga: ICG tenía 5, AtlanticERP nunca lo recibió (stock_quantity nulo).
        [, $nuncaCargado] = $this->producto(null, 5.0);
        [, $sinStockEnIcg] = $this->producto(null, 0.0);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->assertNull($this->base($nuncaCargado));   // la primera corrida lo siembra con el stock de ICG
        $this->assertSame(0, $this->base($sinStockEnIcg)); // 0 conocido: no hay nada que sembrar
    }

    public function test_la_auditoria_registra_el_contexto_real_del_proceso_y_avisa_por_correo(): void
    {
        $this->producto(5, 5.0);

        $this->init(['--apply' => true, '--operador' => 'Prueba'])->assertExitCode(0);

        $this->tenant();
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_INIT_STOCK_BASELINE)->firstOrFail();
        $this->assertArrayHasKey('usuario_so', $evento->metadata);
        $this->assertArrayHasKey('host', $evento->metadata);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Modules\Legacy\Mail\LegacySyncAlertMail::class, fn ($m): bool => str_contains($m->titulo, 'Línea base'));
    }
}
