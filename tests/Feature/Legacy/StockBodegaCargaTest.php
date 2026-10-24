<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Exceptions\InsufficientStockException;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Legacy\Models\AlmacenEquivalencia;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\StockBodegaLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Database\Seeders\AlmacenEquivalenciaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1136 F6 — `legacy:cargar-stock-bodegas`, `legacy:verify-stock-bodegas`, `legacy:revertir-stock-bodegas` y `AlmacenEquivalenciaSeeder`, sobre la BD REAL de pruebas con
 * fixtures de `legacy.stock_almacen` / `legacy.productos`: nunca ICG ni red.
 *
 * Los comandos hacen `Tenant::forgetCurrent()` en su `finally`: cada llamada a Artisan deja el tenant apagado, `tenant()` lo vuelve a prender.
 */
final class StockBodegaCargaTest extends FeatureTestCase
{
    private int $cod = 910000;

    private int $ref = 0;

    private string $ultimaSalida = '';

    protected function setUp(): void
    {
        parent::setUp();
        StockBodegaLoader::$antesDeVerificar = null;
        $this->tenant();
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
        config(['legacy.carga_stock_excluir_codarticulos' => [2999, 68602]]);
    }

    protected function tearDown(): void
    {
        StockBodegaLoader::$antesDeVerificar = null;
        parent::tearDown();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function bodega(string $nombre): int
    {
        return (int) Warehouse::query()->where('name', $nombre)->value('id');
    }

    /** @param array<string, mixed> $opciones */
    private function correr(string $comando, array $opciones = []): int
    {
        $code = Artisan::call($comando, ['--tenant' => 'atlantic'] + $opciones);
        $this->tenant();

        $this->ultimaSalida = Artisan::output();

        return $code;
    }

    private function salida(): string
    {
        return $this->ultimaSalida;
    }

    private function confirmar(string $codalmacen, string $bodega, string $estado = AlmacenEquivalencia::ESTADO_CONFIRMADO): void
    {
        AlmacenEquivalencia::query()->create(['codalmacen_icg' => $codalmacen, 'warehouse_id' => $this->bodega($bodega), 'estado' => $estado]);
    }

    /** Producto de catálogo con stock agregado inicial (de AL4) y su artículo ICG ligado. @return array{int, int} [catalog_product_id, icg_codarticulo] */
    private function producto(?int $stockQuantity = null, bool $descatalogado = false, bool $ligado = true): array
    {
        $this->ref++;
        $cp = CatalogProduct::create(['reference' => 'F6-' . $this->cod . '-' . $this->ref, 'name' => 'Producto ' . $this->cod, 'price_full' => 1.0, 'cost' => 2.0, 'is_active' => true, 'stock_quantity' => $stockQuantity]);
        $codigo = ++$this->cod;
        Producto::query()->create(['icg_codarticulo' => $codigo, 'descripcion' => "ICG {$codigo}", 'ref_proveedor' => 'REF-' . $codigo, 'descatalogado' => $descatalogado, 'catalog_product_id' => $ligado ? $cp->id : null]);

        return [(int) $cp->id, $codigo];
    }

    private function stockIcg(int $codarticulo, string $almacen, ?float $stock, string $talla = '.'): void
    {
        DB::table('atlantic_legacy.stock_almacen')->insert([
            'codarticulo' => $codarticulo, 'talla' => $talla, 'color' => '.', 'codalmacen' => $almacen, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array{int, int}> [warehouse_id, quantity] */
    private function filas(int $cp): array
    {
        return ProductWarehouseStock::query()->where('catalog_product_id', $cp)->orderBy('warehouse_id')->get(['warehouse_id', 'quantity'])->map(fn ($r): array => [(int) $r->warehouse_id, (int) $r->quantity])->all();
    }

    /** @return array<string, string> */
    private function foto(): array
    {
        return [
            'stock' => DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson(),
            'mov'   => DB::table((new InventoryMovement())->getTable())->orderBy('id')->get()->toJson(),
            'cp'    => DB::table((new CatalogProduct())->getTable())->orderBy('id')->get(['id', 'stock_quantity'])->toJson(),
            'audit' => (string) AuditLog::query()->whereIn('action', [AuditService::LEGACY_CARGAR_STOCK_BODEGAS, AuditService::LEGACY_REVERTIR_STOCK_BODEGAS])->count(),
        ];
    }

    public function test_dry_run_por_defecto_no_escribe_nada(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        [, $icg] = $this->producto(10);
        $this->stockIcg($icg, 'AL2', 5);
        $antes = $this->foto();

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas'));
        $this->assertStringContainsString('DRY-RUN', $this->salida());
        $this->assertSame($antes, $this->foto());
    }

    public function test_apply_exige_operador(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        [, $icg] = $this->producto();
        $this->stockIcg($icg, 'AL2', 5);
        $antes = $this->foto();

        $this->assertSame(1, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true]));
        $this->assertSame($antes, $this->foto());
    }

    public function test_siembra_stock_kardex_y_agregado_con_negativos_redondeo_y_exclusiones(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $obarrio = $this->bodega('Showroom Obarrio');

        [$pos, $icgPos] = $this->producto(100);
        [$neg, $icgNeg] = $this->producto(null);
        [$frac, $icgFrac] = $this->producto(0);
        [$tallas, $icgTallas] = $this->producto(7);
        [$cero, $icgCero] = $this->producto(3);
        [$nulo, $icgNulo] = $this->producto(3);
        [$otroAlm, $icgOtro] = $this->producto(3);
        [$sinCat, $icgSinCat] = $this->producto(null, ligado: false);
        [$excl, $icgExcl] = $this->producto(null);

        $this->stockIcg($icgPos, 'AL2', 12);
        $this->stockIcg($icgNeg, 'AL2', -7);
        $this->stockIcg($icgFrac, 'AL2', 5.8);
        $this->stockIcg($icgTallas, 'AL2', 2, 'S');
        $this->stockIcg($icgTallas, 'AL2', 3, 'M');
        $this->stockIcg($icgCero, 'AL2', 0);
        $this->stockIcg($icgNulo, 'AL2', null);
        $this->stockIcg($icgOtro, 'ALX', 9);          // almacén no confirmado: no se carga
        $this->stockIcg($icgSinCat, 'AL2', 4);
        $this->stockIcg($icgExcl, 'AL2', 6);
        config(['legacy.carga_stock_excluir_codarticulos' => [$icgExcl]]);

        $auditoriaPrevia = (int) AuditLog::query()->max('id');
        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));

        $this->assertSame([[$obarrio, 12]], $this->filas($pos));
        $this->assertSame([[$obarrio, -7]], $this->filas($neg), 'los negativos se cargan tal cual');
        $this->assertSame([[$obarrio, 6]], $this->filas($frac), '5,8 se redondea a 6');
        $this->assertSame([[$obarrio, 5]], $this->filas($tallas), 'varias tallas suman en una fila');
        foreach ([$cero, $nulo, $otroAlm, $sinCat, $excl] as $id) {
            $this->assertSame([], $this->filas($id));
        }

        // Agregado: delta firmado sobre catalog_products.stock_quantity (mismo criterio que KardexService::registrar()).
        $this->assertSame(112, (int) CatalogProduct::find($pos)->stock_quantity);
        $this->assertSame(-7, (int) CatalogProduct::find($neg)->stock_quantity);
        $this->assertSame(6, (int) CatalogProduct::find($frac)->stock_quantity);
        $this->assertSame(12, (int) CatalogProduct::find($tallas)->stock_quantity);
        $this->assertSame(3, (int) CatalogProduct::find($cero)->stock_quantity, 'sin carga, el agregado no cambia');

        // Kardex: una línea Ajuste por fila, saldo_inicial 0, saldo_resultante = quantity.
        $mov = InventoryMovement::query()->where('catalog_product_id', $neg)->firstOrFail();
        $this->assertSame(InventoryMovement::TIPO_AJUSTE, $mov->tipo);
        $this->assertSame(-7, (int) $mov->cantidad);
        $this->assertSame(0, (int) $mov->saldo_inicial);
        $this->assertSame(-7, (int) $mov->saldo_resultante);
        $this->assertSame(StockBodegaLoader::ENTIDAD_KARDEX, $mov->referencia_entidad_tipo);
        $this->assertStringContainsString('almacén AL2', $mov->referencia_texto);
        $this->assertSame(4, InventoryMovement::query()->where('referencia_entidad_tipo', StockBodegaLoader::ENTIDAD_KARDEX)->count());

        $m = AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_STOCK_BODEGAS)->firstOrFail()->metadata;
        $this->assertSame(4, $m['filas_sembradas']);
        $this->assertSame(1, $m['sin_catalogo']);
        $this->assertSame(1, $m['excluidos']);
        $this->assertSame(-7, $m['unidades_negativas']);
        $this->assertSame('tests', $m['operador']);
        $this->assertSame('AL2', $m['almacen_icg']);
        // Un solo evento resumen: la carga no inunda audit_logs fila por fila.
        // (solo lo creado por ESTA corrida: en la suite completa la tabla puede traer filas de otras pruebas).
        $this->assertSame(1, AuditLog::query()->where('id', '>', $auditoriaPrevia)->where('entity_type', 'product_warehouse_stock')->count());
    }

    public function test_es_idempotente_y_no_pisa_filas_existentes(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $obarrio = $this->bodega('Showroom Obarrio');
        [$nuevo, $icgNuevo] = $this->producto(0);
        [$existente, $icgExistente] = $this->producto(4);
        ProductWarehouseStock::create(['catalog_product_id' => $existente, 'warehouse_id' => $obarrio, 'quantity' => 4]);
        $this->stockIcg($icgNuevo, 'AL2', 9);
        $this->stockIcg($icgExistente, 'AL2', 50);

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertSame([[$obarrio, 9]], $this->filas($nuevo));
        $this->assertSame([[$obarrio, 4]], $this->filas($existente), 'una fila existente no se pisa ni se suma');
        $this->assertSame(4, (int) CatalogProduct::find($existente)->stock_quantity);

        $despuesPrimera = $this->foto();
        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertSame($despuesPrimera, $this->foto(), 'la segunda corrida no crea ni cambia nada');
    }

    public function test_el_descatalogado_equivalente_suma_sobre_el_producto_del_activo(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $obarrio = $this->bodega('Showroom Obarrio');
        [$activo, $icgActivo] = $this->producto(0);
        $this->ref++;
        $icgDesc = ++$this->cod;
        Producto::query()->create(['icg_codarticulo' => $icgDesc, 'descripcion' => 'viejo', 'ref_proveedor' => 'REF-' . $icgActivo, 'descatalogado' => true, 'catalog_product_id' => null, 'equivalente_icg_codarticulo' => $icgActivo]);
        $this->stockIcg($icgActivo, 'AL2', 5);
        $this->stockIcg($icgDesc, 'AL2', 3);

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));

        $this->assertSame([[$obarrio, 8]], $this->filas($activo), 'activo + equivalente descatalogado = una sola fila');
        $this->assertSame(1, ProductWarehouseStock::query()->where('warehouse_id', $obarrio)->count());
    }

    public function test_destino_bodega_central_o_solo_consulta_aborta_sin_escribir(): void
    {
        [, $icg] = $this->producto();
        $this->stockIcg($icg, 'AL4', 5);
        $this->stockIcg($icg, 'AL3', 5);
        $antes = $this->foto();

        $this->confirmar('AL4', 'Bodega Central');
        $this->assertSame(1, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertStringContainsString('Bodega Central', $this->salida());
        $this->assertSame($antes, $this->foto());

        AlmacenEquivalencia::query()->where('codalmacen_icg', 'AL4')->delete();
        $this->confirmar('AL3', 'Bodega Zona Libre');
        $this->assertSame(1, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertStringContainsString('solo_consulta', $this->salida());
        $this->assertSame($antes, $this->foto());
    }

    public function test_equivalencia_no_confirmada_no_carga_nada(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio', AlmacenEquivalencia::ESTADO_PROPUESTO);
        [, $icg] = $this->producto();
        $this->stockIcg($icg, 'AL2', 5);
        $antes = $this->foto();

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertStringContainsString('No hay equivalencias CONFIRMADAS', $this->salida());
        $this->assertSame($antes, $this->foto());
    }

    public function test_rollback_total_del_almacen_ante_una_discrepancia(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        [$a, $icgA] = $this->producto(5);
        [$b, $icgB] = $this->producto(5);
        $this->stockIcg($icgA, 'AL2', 3);
        $this->stockIcg($icgB, 'AL2', 4);
        $antes = $this->foto();

        // Simula actividad concurrente que altera el agregado de un producto sembrado entre la escritura y la verificación.
        StockBodegaLoader::$antesDeVerificar = static function () use ($a): void {
            DB::table((new CatalogProduct())->getTable())->where('id', $a)->update(['stock_quantity' => DB::raw('stock_quantity + 100')]);
        };

        $this->assertSame(1, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertStringContainsString('ROLLBACK', $this->salida());
        $this->assertSame($antes, $this->foto(), 'ni stock, ni Kardex, ni agregado, ni auditoría');
    }

    public function test_un_almacen_que_falla_no_deshace_los_ya_cargados(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $this->confirmar('ALX', 'Merma');
        [$a, $icgA] = $this->producto(0);
        [$b, $icgB] = $this->producto(0);
        $this->stockIcg($icgA, 'AL2', 3);
        $this->stockIcg($icgB, 'ALX', 4);

        // Falla solo el segundo almacén (ALX): el primero ya quedó confirmado en su propia transacción.
        $llamadas = 0;
        StockBodegaLoader::$antesDeVerificar = static function () use (&$llamadas, $b): void {
            if (++$llamadas === 2) {
                DB::table((new CatalogProduct())->getTable())->where('id', $b)->update(['stock_quantity' => 999]);
            }
        };

        $this->assertSame(1, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertSame([[$this->bodega('Showroom Obarrio'), 3]], $this->filas($a));
        $this->assertSame([], $this->filas($b));
    }

    public function test_verify_ok_y_detecta_diferencias(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        [$a, $icgA] = $this->producto(0);
        $this->stockIcg($icgA, 'AL2', 8);

        $this->assertSame(1, $this->correr('legacy:verify-stock-bodegas'), 'antes de cargar hay diferencia');
        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        $this->assertSame(0, $this->correr('legacy:verify-stock-bodegas'));
        $this->assertStringContainsString('Verificación OK', $this->salida());

        DB::table((new ProductWarehouseStock())->getTable())->where('catalog_product_id', $a)->update(['quantity' => 1]);
        $antes = $this->foto();
        $this->assertSame(1, $this->correr('legacy:verify-stock-bodegas'));
        $this->assertStringContainsString('DIFERENCIA', $this->salida());
        $this->assertSame($antes, $this->foto(), 'verify no escribe');
    }

    public function test_revertir_deja_todo_como_estaba_y_respeta_lo_movido_despues(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $obarrio = $this->bodega('Showroom Obarrio');
        [$limpio, $icgLimpio] = $this->producto(10);
        [$movido, $icgMovido] = $this->producto(10);
        $this->stockIcg($icgLimpio, 'AL2', 5);
        $this->stockIcg($icgMovido, 'AL2', 6);
        $inicial = $this->foto();

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));

        // Bodega registra un movimiento posterior sobre uno de los dos.
        app(KardexService::class)->registrar($movido, $obarrio, InventoryMovement::TIPO_AJUSTE, -2, 'ajuste de Bodega');
        $this->tenant();

        // Dry-run: no borra.
        $antesDry = $this->foto();
        $this->assertSame(0, $this->correr('legacy:revertir-stock-bodegas'));
        $this->assertSame($antesDry, $this->foto());

        $this->assertSame(1, $this->correr('legacy:revertir-stock-bodegas', ['--apply' => true]), 'sin --operador falla');
        $this->assertSame(0, $this->correr('legacy:revertir-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));

        $this->assertSame([], $this->filas($limpio));
        $this->assertSame(10, (int) CatalogProduct::find($limpio)->stock_quantity, 'el agregado vuelve a lo de antes');
        $this->assertSame(0, InventoryMovement::query()->where('catalog_product_id', $limpio)->count());
        $this->assertSame([[$obarrio, 4]], $this->filas($movido), 'lo movido después por Bodega no se toca');
        $this->assertSame(2, InventoryMovement::query()->where('catalog_product_id', $movido)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::LEGACY_REVERTIR_STOCK_BODEGAS)->count());
        $this->assertNotSame($inicial['stock'], $this->foto()['stock']);
    }

    /** R2 del diseño: KardexService::registrar() ACEPTA un Ajuste negativo con saldo 0; solo la Reubicación tiene piso. */
    public function test_kardex_service_acepta_ajuste_negativo_con_saldo_cero_pero_no_reubicacion(): void
    {
        [$cp] = $this->producto(0);
        $obarrio = $this->bodega('Showroom Obarrio');
        $kardex = app(KardexService::class);

        $mov = $kardex->registrar($cp, $obarrio, InventoryMovement::TIPO_AJUSTE, -7, 'prueba R2');
        $this->assertSame(0, (int) $mov->saldo_inicial);
        $this->assertSame(-7, (int) $mov->saldo_resultante);

        $this->expectException(InsufficientStockException::class);
        $kardex->registrar($cp, $obarrio, InventoryMovement::TIPO_REUBICACION, -1, 'no debe dejar negativo');
    }

    /** La carga deja exactamente lo mismo que dejaría registrar() fila por fila (columnas del Kardex y delta del agregado). */
    public function test_la_carga_equivale_a_registrar_fila_por_fila(): void
    {
        $this->confirmar('AL2', 'Showroom Obarrio');
        $obarrio = $this->bodega('Showroom Obarrio');
        $reubicacion = $this->bodega('Showroom Cliente');
        [$viaCarga, $icgCarga] = $this->producto(10);
        [$viaKardex] = $this->producto(10);
        $this->stockIcg($icgCarga, 'AL2', -4);

        $this->assertSame(0, $this->correr('legacy:cargar-stock-bodegas', ['--apply' => true, '--operador' => 'tests']));
        app(KardexService::class)->registrar($viaKardex, $reubicacion, InventoryMovement::TIPO_AJUSTE, -4, 'equivalente');
        $this->tenant();

        $a = InventoryMovement::query()->where('catalog_product_id', $viaCarga)->firstOrFail();
        $b = InventoryMovement::query()->where('catalog_product_id', $viaKardex)->firstOrFail();
        foreach (['tipo', 'cantidad', 'saldo_inicial', 'saldo_resultante'] as $campo) {
            $this->assertSame($b->{$campo}, $a->{$campo}, $campo);
        }
        $this->assertSame((int) CatalogProduct::find($viaKardex)->stock_quantity, (int) CatalogProduct::find($viaCarga)->stock_quantity);
        $this->assertSame([[$obarrio, -4]], $this->filas($viaCarga));
    }

    public function test_seeder_siembra_confirmadas_es_idempotente_y_no_pisa(): void
    {
        $this->assertSame(0, AlmacenEquivalencia::query()->count());

        (new AlmacenEquivalenciaSeeder())->run();
        $this->tenant();
        $this->assertSame(count(AlmacenEquivalenciaSeeder::MAPA), AlmacenEquivalencia::query()->where('estado', 'confirmado')->count());
        $this->assertSame($this->bodega('Merma'), (int) AlmacenEquivalencia::query()->where('codalmacen_icg', 'ALX')->value('warehouse_id'));

        // Un ajuste manual posterior no se deshace con una segunda corrida.
        AlmacenEquivalencia::query()->where('codalmacen_icg', 'ALR')->update(['estado' => 'propuesto']);
        (new AlmacenEquivalenciaSeeder())->run();
        $this->tenant();
        $this->assertSame(count(AlmacenEquivalenciaSeeder::MAPA), AlmacenEquivalencia::query()->count());
        $this->assertSame('propuesto', AlmacenEquivalencia::query()->where('codalmacen_icg', 'ALR')->value('estado'));
    }

    public function test_seeder_falla_sin_escribir_si_falta_una_bodega(): void
    {
        Warehouse::query()->where('name', 'Reserva Servicios')->update(['name' => 'Reserva Servicios (renombrada)']);

        try {
            (new AlmacenEquivalenciaSeeder())->run();
            $this->fail('debía fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reserva Servicios', $e->getMessage());
        }
        $this->tenant();
        $this->assertSame(0, AlmacenEquivalencia::query()->count(), 'no siembra nada a medias');
    }
}
