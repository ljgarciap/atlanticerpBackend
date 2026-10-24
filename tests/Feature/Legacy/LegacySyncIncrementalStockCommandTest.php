<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use App\Modules\Legacy\Mail\LegacySyncAlertMail;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1114 — `legacy:sync-incremental-stock` sobre la BD REAL de pruebas, con ICG fakeado.
 * Cubre los criterios de aceptación de la spec (docs/specs/scrum1114-icg-stock-delta-paralelo.md).
 */
final class LegacySyncIncrementalStockCommandTest extends FeatureTestCase
{
    private int $cod = 870000;
    private static ?int $fallarAlActualizarBaseDe = null;

    protected function tearDown(): void
    {
        self::$fallarAlActualizarBaseDe = null; // el listener de Producto::updating queda inerte para el resto de la suite
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        $this->habilitar();
    }

    /** El comando hace `Tenant::forgetCurrent()` al terminar: cada lectura de verificación lo vuelve a activar. */
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<SyncRun> */
    private function runs(): \Illuminate\Database\Eloquent\Builder
    {
        $this->tenant();

        return SyncRun::query()->where('entidad', 'stock');
    }

    /** Sync encendido, línea base inicializada y umbrales que nunca se disparan (salvo que el test los baje). */
    private function habilitar(array $extra = []): void
    {
        StockSyncSettings::current()->update($extra + [
            'activo'                        => true,
            'baseline_inicializada_at'      => now(),
            'max_productos_con_delta_pct'   => 100,
            'max_unidades_abs_por_producto' => 1_000_000,
            'max_unidades_abs_total'        => 10_000_000,
            'correos_alerta'                => [],
        ]);
    }

    private function central(): int
    {
        return (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');
    }

    /**
     * Producto ligado a ICG. `$base` = stock_al4_aplicado; `$central` = cantidad de la fila de Bodega Central
     * (null = sin fila). Devuelve [catalog_product_id, icg_codarticulo].
     *
     * @return array{0: int, 1: int}
     */
    private function producto(?int $stock, ?int $base, ?int $central = null, bool $activo = true, bool $descatalogado = false): array
    {
        $cat = CatalogProduct::create(['reference' => 'SD-' . ++$this->cod, 'name' => 'Producto ' . $this->cod, 'price_full' => 1.0, 'is_active' => $activo, 'stock_quantity' => $stock]);
        Producto::query()->create(['icg_codarticulo' => $this->cod, 'descripcion' => 'ICG ' . $this->cod, 'descatalogado' => $descatalogado, 'catalog_product_id' => $cat->id, 'stock_al4_aplicado' => $base]);
        if ($central !== null) {
            ProductWarehouseStock::create(['catalog_product_id' => $cat->id, 'warehouse_id' => $this->central(), 'quantity' => $central]);
        }

        return [(int) $cat->id, $this->cod];
    }

    private ?FakeStockAl4Extractor $fakeIcg = null;

    /**
     * `$this->artisan()` reutiliza la MISMA instancia del comando entre llamadas del mismo test: un fake nuevo
     * por llamada no le llega. Se crea una vez y los cambios posteriores mutan ese mismo objeto.
     *
     * @param array<int, float> $al4
     */
    private function icg(array $al4): void
    {
        if ($this->fakeIcg === null) {
            $this->fakeIcg = new FakeStockAl4Extractor($al4);
            $this->app->instance(StockAl4ExtractorContract::class, $this->fakeIcg);

            return;
        }
        $this->fakeIcg->poner($al4);
    }

    /** @param array<string, mixed> $opciones */
    private function sync(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('legacy:sync-incremental-stock', ['--tenant' => 'atlantic', '--operador' => 'Prueba'] + $opciones);
    }

    private function stock(int $catId): ?int
    {
        $this->tenant();
        $v = CatalogProduct::query()->whereKey($catId)->value('stock_quantity');

        return $v === null ? null : (int) $v;
    }

    private function enCentral(int $catId): ?int
    {
        $this->tenant();
        $v = ProductWarehouseStock::query()->where('catalog_product_id', $catId)->where('warehouse_id', $this->central())->value('quantity');

        return $v === null ? null : (int) $v;
    }

    private function base(int $cod): ?int
    {
        $this->tenant();
        $v = Producto::query()->where('icg_codarticulo', $cod)->value('stock_al4_aplicado');

        return $v === null ? null : (int) $v;
    }

    private function movimientos(int $catId): int
    {
        $this->tenant();
        return InventoryMovement::query()->where('catalog_product_id', $catId)->count();
    }

    private function atlanticerpMueve(int $catId, int $cantidad): void
    {
        app(KardexService::class)->registrar($catId, $this->central(), $cantidad < 0 ? InventoryMovement::TIPO_SALIDA : InventoryMovement::TIPO_ENTRADA, $cantidad, 'movimiento propio de AtlanticERP');
    }

    public function test_ejemplo_canonico_icg_baja_10_y_atlanticerp_movio_5_queda_en_85(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->atlanticerpMueve($cat, -5);
        $this->assertSame(95, $this->stock($cat));
        $salidasAntes = InventoryMovement::query()->where('tipo', InventoryMovement::TIPO_SALIDA)->count();

        $this->icg([$cod => 90.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(85, $this->stock($cat));
        $this->assertSame(85, $this->enCentral($cat));
        $this->assertSame(90, $this->base($cod));

        $run = $this->runs()->firstOrFail();
        $this->tenant();
        $mov = InventoryMovement::query()->where('catalog_product_id', $cat)->where('tipo', InventoryMovement::TIPO_AJUSTE)->firstOrFail();
        $this->assertSame(-10, (int) $mov->cantidad);
        $this->assertSame('legacy.sync_run', $mov->referencia_entidad_tipo);
        $this->assertSame((int) $run->id, (int) $mov->referencia_entidad_id);
        $this->assertNull($mov->realizado_por);
        // El KPI de rotación cuenta `Salida`: el sync nunca debe crear una.
        $this->tenant();
        $this->assertSame($salidasAntes, InventoryMovement::query()->where('tipo', InventoryMovement::TIPO_SALIDA)->count());
    }

    public function test_delta_positivo_entrada_en_icg_se_suma(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->atlanticerpMueve($cat, -5);

        $this->icg([$cod => 120.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(115, $this->stock($cat));
        $this->assertSame(115, $this->enCentral($cat));
        $this->assertSame(120, $this->base($cod));
    }

    public function test_sin_cambio_en_icg_no_crea_movimientos_y_la_segunda_corrida_es_idempotente(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->icg([$cod => 100.0]);
        $this->sync()->assertExitCode(0);
        $this->assertSame(0, $this->movimientos($cat));

        // Con delta: aplicar dos veces seguidas no debe aplicar dos veces.
        $this->icg([$cod => 70.0]);
        $this->sync()->assertExitCode(0);
        $this->sync()->assertExitCode(0);

        $this->assertSame(70, $this->stock($cat));
        $this->assertSame(1, $this->movimientos($cat));
        $this->assertSame(70, $this->base($cod));
    }

    public function test_producto_sin_stock_en_atlanticerp_parte_de_cero_y_crea_la_fila_de_central(): void
    {
        [$cat, $cod] = $this->producto(null, null, null);

        $this->icg([$cod => 7.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(7, $this->stock($cat));
        $this->assertSame(7, $this->enCentral($cat));
        $this->assertSame(7, $this->base($cod));
        $this->assertSame(1, $this->movimientos($cat));
    }

    public function test_producto_sin_base_y_sin_fila_en_icg_solo_inicializa_la_base_a_cero(): void
    {
        [$cat, $cod] = $this->producto(null, null, null);

        $this->icg([$cod + 99999 => 1.0]); // otro artículo: este no aparece en AL4
        $this->sync()->assertExitCode(0);

        $this->assertNull($this->stock($cat));
        $this->assertNull($this->enCentral($cat));
        $this->assertSame(0, $this->base($cod));
        $this->assertSame(0, $this->movimientos($cat));
    }

    public function test_producto_sin_base_pero_con_stock_en_atlanticerp_se_retiene_y_se_reporta(): void
    {
        [$cat, $cod] = $this->producto(10, null, 10);

        $this->icg([$cod => 4.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(10, $this->stock($cat));
        $this->assertNull($this->base($cod));
        $this->assertSame(0, $this->movimientos($cat));
        $resumen = $this->runs()->firstOrFail()->resumen;
        $this->assertSame(1, $resumen['retenidos']);
        $this->assertContains($cat, $resumen['retenidos_ids']);
    }

    public function test_un_delta_que_deja_el_stock_en_negativo_se_aplica_y_se_reporta(): void
    {
        [$cat, $cod] = $this->producto(5, 5, 5);
        $this->atlanticerpMueve($cat, -2); // 3

        $this->icg([$cod => 0.0]); // ICG vendió las 5 -> delta -5
        $this->sync()->assertExitCode(0);

        $this->assertSame(-2, $this->stock($cat));
        $this->assertSame(-2, $this->enCentral($cat));
        $resumen = $this->runs()->firstOrFail()->resumen;
        $this->assertSame(1, $resumen['aplicado']['negativos']);
        $this->assertContains($cat, $resumen['aplicado']['negativos_ids']);
    }

    public function test_los_valores_fraccionarios_de_icg_se_redondean_a_entero(): void
    {
        [$cat, $cod] = $this->producto(10, 10, 10);

        $this->icg([$cod => 12.6]); // round -> 13, delta +3
        $this->sync()->assertExitCode(0);

        $this->assertSame(13, $this->stock($cat));
        $this->assertSame(13, $this->base($cod));
    }

    public function test_inactivos_y_descatalogados_no_se_tocan_ni_avanza_su_base(): void
    {
        [$catA, $codA] = $this->producto(10, 10, 10, activo: false);
        [$catD, $codD] = $this->producto(10, 10, 10, descatalogado: true);

        $this->icg([$codA => 0.0, $codD => 0.0]);
        $this->sync()->assertExitCode(0);

        foreach ([[$catA, $codA], [$catD, $codD]] as [$cat, $cod]) {
            $this->assertSame(10, $this->stock($cat));
            $this->assertSame(10, $this->base($cod));
            $this->assertSame(0, $this->movimientos($cat));
        }
        $this->assertGreaterThanOrEqual(2, $this->runs()->firstOrFail()->resumen['omitidos_inactivos']);
    }

    public function test_un_producto_no_ligado_a_icg_no_se_toca(): void
    {
        $sinIcg = CatalogProduct::create(['reference' => 'SD-LAF-1', 'name' => 'Lafayette', 'price_full' => 1.0, 'is_active' => true, 'stock_quantity' => 50]);
        [, $cod] = $this->producto(10, 10, 10);

        $this->icg([$cod => 9.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(50, $this->stock((int) $sinIcg->id));
    }

    public function test_dry_run_informa_sin_escribir_ni_registrar_la_corrida(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);

        $this->icg([$cod => 60.0]);
        $this->sync(['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(100, $this->base($cod));
        $this->assertSame(0, $this->runs()->count());
    }

    public function test_si_la_base_cambia_entre_el_calculo_y_la_aplicacion_no_se_aplica_un_delta_viejo(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $service = app(\App\Modules\Legacy\Services\StockDeltaService::class);

        $plan = $service->plan([$cod => 90.0]);
        // Otra corrida concurrente ya movió la base.
        Producto::query()->where('icg_codarticulo', $cod)->update(['stock_al4_aplicado' => 90]);
        $res = $service->apply($plan, 999, $this->central());

        $this->assertSame(1, $res['omitidos_cambiados']);
        $this->assertSame(0, $res['aplicados']);
        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(0, $this->movimientos($cat));
    }

    public function test_circuit_breaker_retiene_la_corrida_no_aplica_nada_y_avisa_por_correo(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->habilitar(['max_unidades_abs_total' => 5]);

        $this->icg([$cod => 50.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(100, $this->base($cod));
        $run = $this->runs()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $run->status);
        $this->assertStringContainsString('unidades absolutas', (string) $run->resumen['circuit_breaker']);
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => $m->entidad === 'stock' && str_contains($m->titulo, 'retenida'));
    }

    public function test_force_aplica_una_corrida_que_supera_un_umbral(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->habilitar(['max_unidades_abs_por_producto' => 5]);

        $this->icg([$cod => 50.0]);
        $this->sync(['--force' => true])->assertExitCode(0);

        $this->assertSame(50, $this->stock($cat));
        $run = $this->runs()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertArrayHasKey('circuit_breaker_forzado', $run->resumen);
    }

    public function test_circuit_breaker_por_porcentaje_de_productos_con_delta(): void
    {
        $a = $this->producto(10, 10, 10);
        $this->producto(10, 10, 10);
        $this->habilitar(['max_productos_con_delta_pct' => 0.0001]); // cualquier delta supera

        $this->icg([$a[1] => 1.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $this->runs()->firstOrFail()->status);
        $this->assertSame(10, $this->stock($a[0]));
    }

    public function test_una_respuesta_vacia_de_icg_es_un_fallo_no_un_stock_en_cero(): void
    {
        [$cat] = $this->producto(100, 100, 100);

        $this->icg([]);
        $this->sync()->assertExitCode(1);

        $this->assertSame(100, $this->stock($cat));
        $run = $this->runs()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => str_contains($m->titulo, 'fallida'));
    }

    public function test_si_icg_no_responde_la_corrida_queda_fallida_sin_tocar_nada(): void
    {
        [$cat] = $this->producto(100, 100, 100);

        $this->app->instance(StockAl4ExtractorContract::class, new FakeStockAl4Extractor([], falla: true));
        $this->sync()->assertExitCode(1);

        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(SyncRun::STATUS_FAILED, $this->runs()->firstOrFail()->status);
    }

    public function test_la_corrida_programada_no_corre_con_el_interruptor_apagado_pero_la_manual_si(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $this->habilitar(['activo' => false]);
        $this->icg([$cod => 80.0]);

        // Programada: sin --operador.
        $this->artisan('legacy:sync-incremental-stock', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->assertSame(100, $this->stock($cat));
        $this->assertSame(0, $this->runs()->count());

        // Manual supervisada.
        $this->sync()->assertExitCode(0);
        $this->assertSame(80, $this->stock($cat));
        $this->assertSame(SyncRun::ORIGEN_MANUAL, $this->runs()->firstOrFail()->origen);
    }

    public function test_sin_la_linea_base_la_corrida_falla_y_queda_registrada_y_avisada(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        StockSyncSettings::current()->update(['baseline_inicializada_at' => null]);

        $this->icg([$cod => 10.0]);
        $this->sync()->assertExitCode(1);

        $this->assertSame(100, $this->stock($cat));
        $run = $this->runs()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('línea base', (string) $run->resumen['error']);
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => str_contains($m->titulo, 'fallida'));
    }

    public function test_una_falla_en_un_producto_revierte_su_movimiento_y_no_infla_los_contadores(): void
    {
        [$catOk, $codOk]   = $this->producto(10, 10, 10);
        [$catMal, $codMal] = $this->producto(10, 10, 10);

        // Fuerza el fallo DESPUÉS del movimiento de kardex: al actualizar la base del segundo producto.
        self::$fallarAlActualizarBaseDe = $codMal;
        Producto::updating(static function (Producto $p): void {
            if (self::$fallarAlActualizarBaseDe !== null && $p->icg_codarticulo === self::$fallarAlActualizarBaseDe) {
                throw new \RuntimeException('boom en la base');
            }
        });

        $this->icg([$codOk => 3.0, $codMal => 3.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(3, $this->stock($catOk));
        $this->assertSame(10, $this->stock($catMal));      // el movimiento del que falló se revirtió con la transacción
        $this->assertSame(10, $this->enCentral($catMal));
        $this->assertSame(10, $this->base($codMal));
        $this->assertSame(0, $this->movimientos($catMal));
        $run = $this->runs()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->resumen['aplicado']['aplicados']);   // solo el que sí se aplicó
        $this->assertSame(1, $run->resumen['aplicado']['errores']);
        $this->assertSame(1, (int) $run->cargados);
    }

    public function test_una_respuesta_parcial_de_icg_retiene_la_corrida_aunque_pase_los_umbrales(): void
    {
        [$a, $codA] = $this->producto(10, 10, 10);
        [$b, $codB] = $this->producto(10, 10, 10);

        // Corrida buena previa: 2 filas leídas.
        $this->icg([$codA => 10.0, $codB => 10.0]);
        $this->sync()->assertExitCode(0);

        // Ahora ICG devuelve solo 1 de 2 (50 % < 80 %): el faltante NO debe leerse como stock 0.
        $this->icg([$codA => 10.0]);
        $this->sync()->assertExitCode(0);

        $this->assertSame(10, $this->stock($b));
        $run = $this->runs()->orderByDesc('id')->firstOrFail();
        $this->assertSame(SyncRun::STATUS_HELD_FOR_REVIEW, $run->status);
        $this->assertStringContainsString('respuesta parcial', (string) $run->resumen['circuit_breaker']);
    }

    public function test_no_corre_dos_veces_en_paralelo_para_el_mismo_tenant(): void
    {
        [$cat, $cod] = $this->producto(100, 100, 100);
        $lock = \Illuminate\Support\Facades\Cache::lock('legacy-sync-stock:atlantic', 60);
        $this->assertTrue($lock->get());

        $this->icg([$cod => 50.0]);
        $this->sync()->assertExitCode(1);
        $this->assertSame(100, $this->stock($cat));

        $lock->release();
        $this->sync()->assertExitCode(0);
        $this->assertSame(50, $this->stock($cat));
    }

    public function test_una_corrida_manual_deja_el_contexto_real_del_proceso_y_avisa_por_correo(): void
    {
        [, $cod] = $this->producto(100, 100, 100);

        $this->icg([$cod => 90.0]);
        $this->sync()->assertExitCode(0);

        $this->tenant();
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_SYNC_STOCK_DELTA)->firstOrFail();
        $this->assertArrayHasKey('usuario_so', $evento->metadata);
        $this->assertArrayHasKey('host', $evento->metadata);
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => str_contains($m->titulo, 'lanzada a mano'));
    }

    public function test_el_informe_lista_los_productos_cuyo_desglose_no_cuadra_con_stock_quantity(): void
    {
        // stock_quantity 10 pero ninguna fila por bodega.
        [$cat, $cod] = $this->producto(10, 10, null);

        $this->icg([$cod => 10.0]);
        $this->sync()->assertExitCode(0);

        $resumen = $this->runs()->firstOrFail()->resumen;
        $this->assertGreaterThanOrEqual(1, $resumen['no_cuadran']);
        $this->assertContains($cat, $resumen['no_cuadran_ids']);
    }

    public function test_el_informe_muestra_cuanto_se_aleja_atlanticerp_de_icg_y_separa_lo_que_no_tiene_movimiento_que_lo_explique(): void
    {
        // A: AtlanticERP tiene 456 e ICG 0, sin ningún movimiento de kardex (stock que llegó por otra vía, p. ej. datos de prueba).
        [$a, $codA] = $this->producto(456, 0, 456);
        // B: ICG 1.700; AtlanticERP vendió 100 por su cuenta (kardex propio) -> 1.600.
        [$b, $codB] = $this->producto(1700, 1700, 1700);
        $this->atlanticerpMueve($b, -100);
        // C: AtlanticERP e ICG iguales.
        [, $codC] = $this->producto(10, 10, 10);

        $this->icg([$codA => 0.0, $codB => 1700.0, $codC => 10.0]);
        $this->sync()->assertExitCode(0);

        $d = $this->runs()->firstOrFail()->resumen['divergencia_atlanticerp'];
        $this->assertSame(2, $d['total']);
        $this->assertSame(1, $d['atlanticerp_con_mas']);
        $this->assertSame(456, $d['unidades_mas']);
        $this->assertSame(1, $d['atlanticerp_con_menos']);
        $this->assertSame(-100, $d['unidades_menos']);
        $this->assertSame(1, $d['sin_movimientos']);          // A: 456 sin ningún movimiento de kardex
        $this->assertSame(1, $d['explicados_por_kardex']);    // B: la diferencia (-100) es igual a la suma de sus movimientos
        $this->assertSame(0, $d['con_residuo']);
        $this->assertSame(456, $d['unidades_sin_explicar']);  // solo A deja unidades sin explicar
        // Primero lo que más unidades deja sin explicar.
        $this->assertSame($codA, $d['mayores'][0]['icg_codarticulo']);
        $this->assertFalse($d['mayores'][0]['con_movimientos_atlanticerp']);
        $this->assertSame(456, $d['mayores'][0]['sin_explicar']);
        $this->assertSame($codB, $d['mayores'][1]['icg_codarticulo']);
        $this->assertTrue($d['mayores'][1]['con_movimientos_atlanticerp']);
        $this->assertSame(-100, $d['mayores'][1]['kardex_atlanticerp']);
        $this->assertSame(0, $d['mayores'][1]['sin_explicar']);
        $this->assertSame(1600, $this->stock($b));            // AtlanticERP manda: el sync no lo "corrige" hacia ICG
    }

    public function test_un_producto_con_movimientos_de_atlanticerp_que_no_alcanzan_a_explicar_la_diferencia_queda_con_residuo(): void
    {
        // ICG 90; AtlanticERP tenía 100 (10 de más sin origen) y además registró una entrada de +4 -> 104.
        [$p, $cod] = $this->producto(100, 90, 100);
        $this->atlanticerpMueve($p, 4);

        $this->icg([$cod => 90.0]);
        $this->sync()->assertExitCode(0);

        $d = $this->runs()->firstOrFail()->resumen['divergencia_atlanticerp'];
        $this->assertSame(1, $d['total']);
        $this->assertSame(0, $d['sin_movimientos']);
        $this->assertSame(0, $d['explicados_por_kardex']);
        $this->assertSame(1, $d['con_residuo']);
        $this->assertSame(14, $d['mayores'][0]['diferencia']);   // 104 - 90
        $this->assertSame(4, $d['mayores'][0]['kardex_atlanticerp']);  // lo que sí explica el kardex
        $this->assertSame(10, $d['mayores'][0]['sin_explicar']); // los 10 de más sin origen
        $this->assertSame(10, $d['unidades_sin_explicar']);
    }

    public function test_los_movimientos_de_otra_bodega_y_los_del_propio_sync_no_cuentan_como_kardex_de_atlanticerp(): void
    {
        // AtlanticERP tiene 50 en Central e ICG 40; hay un movimiento de +10 pero en OTRA bodega: no explica nada de Central.
        [$p, $cod] = $this->producto(50, 40, 50);
        $otra = (int) Warehouse::query()->where('name', 'Showroom Obarrio')->value('id');
        app(KardexService::class)->registrar($p, $otra, InventoryMovement::TIPO_ENTRADA, 10, 'entrada en otra bodega');

        $this->icg([$cod => 40.0]);
        $this->sync()->assertExitCode(0);

        $d = $this->runs()->firstOrFail()->resumen['divergencia_atlanticerp'];
        $this->assertSame(1, $d['sin_movimientos']);                  // ningún movimiento propio EN CENTRAL
        $this->assertSame(0, $d['mayores'][0]['kardex_atlanticerp']);
        $this->assertSame(10, $d['mayores'][0]['sin_explicar']);
    }

    public function test_la_diferencia_con_icg_se_conserva_cuando_icg_se_mueve_y_no_depende_de_aplicar(): void
    {
        [$a, $codA] = $this->producto(456, 0, 456);

        // Dry-run: informa la misma divergencia sin escribir nada.
        $this->icg([$codA => -10.0]); // ICG vendió 10 pero todavía no se aplicó
        $this->sync(['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(456, $this->stock($a));

        // Aplicado: AtlanticERP = 456 + (-10) = 446; la diferencia contra ICG (-10) sigue siendo +456.
        $this->sync()->assertExitCode(0);
        $this->assertSame(446, $this->stock($a));
        $d = $this->runs()->firstOrFail()->resumen['divergencia_atlanticerp'];
        $this->assertSame(1, $d['total']);
        $this->assertSame(456, $d['mayores'][0]['diferencia']);
        $this->assertSame(446, $d['mayores'][0]['atlanticerp']);
        $this->assertSame(-10, $d['mayores'][0]['icg']);
        $this->assertSame(0, $d['mayores'][0]['kardex_atlanticerp']);   // el movimiento del propio sync NO cuenta como kardex de AtlanticERP
        $this->assertSame(456, $d['mayores'][0]['sin_explicar']);
    }

    public function test_cada_corrida_deja_un_evento_de_auditoria_con_el_operador(): void
    {
        [, $cod] = $this->producto(100, 100, 100);

        $this->icg([$cod => 90.0]);
        $this->sync()->assertExitCode(0);

        $this->tenant();
        $evento = AuditLog::query()->where('action', AuditService::LEGACY_SYNC_STOCK_DELTA)->firstOrFail();
        $this->assertSame('Prueba', $evento->metadata['operador']);
        $this->assertSame(1, $evento->metadata['con_delta']);
    }
}
