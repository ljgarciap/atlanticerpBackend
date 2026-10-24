<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\Integrations\Lafayette\Services\LafayetteProductoLoader;
use App\Modules\Integrations\Lafayette\Services\ProductoMapper;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 — `LafayetteProductoLoader` contra la BD REAL de pruebas (no un fake): el `INSERT ... ON CONFLICT ... WHERE ...
 * IS DISTINCT FROM ... RETURNING (xmax = 0)` solo se valida ejecutándolo en Postgres. Lección de SCRUM-984: un fake de la
 * interfaz pasa aunque el contrato con el motor esté mal.
 */
final class LafayetteProductoLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loader(): LafayetteProductoLoader
    {
        return $this->app->make(LafayetteProductoLoader::class);
    }

    /** @return list<MappedLafayetteProducto> */
    private static function muestra(): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../../Fixtures/Lafayette/vista_productos_lighting_muestra.php';
        $rows = array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw);

        return (new ProductoMapper())->buildPayloads(new ExtractedProductos($rows));
    }

    /** @return list<MappedLafayetteProducto> */
    private static function sinteticas(int $n, int $disponible = 10): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = new MappedLafayetteProducto(
                lafayetteId: sprintf('SYN%07d', $i),
                referencia: "REF-{$i}",
                descripcion: "Producto sintético número {$i}",
                marca: 'SYN',
                categoria: 'CAT-' . ($i % 7),
                urlImagen: null,
                disponible: $disponible,
                precio5: 1.5,
                precio6: 2.5,
                fuenteActualizadoAt: null,
            );
        }

        return $out;
    }

    public function test_primera_carga_inserta_todo_y_deduplica_ids_repetidos(): void
    {
        $r = $this->loader()->load(self::muestra());

        // 20 filas en la muestra, una con id repetido (LNP0000001) => 19 únicas.
        $this->assertSame(19, $r['unicos']);
        $this->assertSame(19, $r['insertados']);
        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(0, $r['sin_cambios']);
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertFalse($r['guarda_activada']);
        $this->assertSame(19, LafayetteProducto::query()->count());

        // Gana la ÚLTIMA aparición del id repetido.
        $first = LafayetteProducto::query()->where('lafayette_id', 'LNP0000001')->firstOrFail();
        $this->assertSame('LAMP COLG DECO 90W (DUPLICADO EN LA VISTA)', $first->descripcion);
        $this->assertSame(29, $first->disponible);
    }

    public function test_guarda_todo_lo_que_trae_la_fuente_sin_exclusiones(): void
    {
        $this->loader()->load(self::muestra());

        $sinRef = LafayetteProducto::query()->where('lafayette_id', 'LNP0000011')->firstOrFail();
        $this->assertNull($sinRef->referencia);
        $this->assertSame('ACCESORIO SIN REFERENCIA EN LA FUENTE', $sinRef->descripcion);

        $sinDesc = LafayetteProducto::query()->where('lafayette_id', 'LNP0000012')->firstOrFail();
        $this->assertNull($sinDesc->descripcion);
        $this->assertNull($sinDesc->precio_5);
        $this->assertNull($sinDesc->precio_6);
        $this->assertSame(1.5, $sinRef->precio_6, 'SCRUM-1125: precio_6 se persiste junto a precio_5');
        $this->assertNull($sinDesc->fuente_actualizado_at);

        $negativo = LafayetteProducto::query()->where('lafayette_id', 'LNP0000014')->firstOrFail();
        $this->assertSame(0, $negativo->disponible);

        $conPrecio = LafayetteProducto::query()->where('lafayette_id', 'LNP0000003')->firstOrFail();
        $this->assertSame(2.15, $conPrecio->precio_5);
        $this->assertSame('LÁMPARA LED BULBO A60 9W 6500K LUZ FRÍA', $conPrecio->descripcion);
    }

    public function test_search_text_generado_es_minuscula_y_sin_acentos(): void
    {
        $this->loader()->load(self::muestra());

        $searchText = (string) DB::table((new LafayetteProducto())->getTable())->where('lafayette_id', 'LNP0000003')->value('search_text');

        $this->assertStringContainsString('lampara led bulbo a60', $searchText);
        $this->assertStringContainsString('lnp0000003', $searchText);
        $this->assertStringContainsString('philips', $searchText);
        $this->assertStringNotContainsString('á', $searchText);
    }

    public function test_segunda_corrida_identica_escribe_cero_filas_y_no_altera_updated_at(): void
    {
        $this->loader()->load(self::muestra());
        $table = (new LafayetteProducto())->getTable();

        // Marca centinela: si la 2a corrida reescribiera alguna fila, updated_at dejaría de ser la centinela.
        DB::table($table)->update(['updated_at' => '2000-01-01 00:00:00+00']);
        $xminAntes = DB::table($table)->orderBy('id')->pluck('id')->count();

        $r = $this->loader()->load(self::muestra());

        $this->assertSame(0, $r['insertados']);
        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(19, $r['sin_cambios']);
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertSame(0, DB::table($table)->where('updated_at', '<>', '2000-01-01 00:00:00+00')->count());
        $this->assertSame($xminAntes, LafayetteProducto::query()->count());
    }

    public function test_un_cambio_actualiza_solo_esa_fila(): void
    {
        $mapped = self::muestra();
        $this->loader()->load($mapped);
        $table = (new LafayetteProducto())->getTable();
        DB::table($table)->update(['updated_at' => '2000-01-01 00:00:00+00']);

        $cambiadas = array_map(static function (MappedLafayetteProducto $p): MappedLafayetteProducto {
            return $p->lafayetteId === 'LNP0000005'
                ? new MappedLafayetteProducto($p->lafayetteId, $p->referencia, $p->descripcion, $p->marca, $p->categoria, $p->urlImagen, 999, 40.0, $p->precio6, $p->fuenteActualizadoAt)
                : $p;
        }, $mapped);

        $r = $this->loader()->load($cambiadas);

        $this->assertSame(0, $r['insertados']);
        $this->assertSame(1, $r['actualizados']);
        $this->assertSame(18, $r['sin_cambios']);
        $this->assertSame(1, DB::table($table)->where('updated_at', '<>', '2000-01-01 00:00:00+00')->count());
        $row = LafayetteProducto::query()->where('lafayette_id', 'LNP0000005')->firstOrFail();
        $this->assertSame(999, $row->disponible);
        $this->assertSame(40.0, $row->precio_5);
    }

    public function test_lotes_pequenos_cruzan_fronteras_sin_perder_ni_duplicar_filas(): void
    {
        config(['lafayette.lote_upsert' => 3]);

        $r = $this->loader()->load(self::sinteticas(20));

        $this->assertSame(20, $r['insertados']);
        $this->assertSame(20, LafayetteProducto::query()->count());

        config(['lafayette.lote_upsert' => 4]);
        $r2 = $this->loader()->load(self::sinteticas(20));
        $this->assertSame(20, $r2['sin_cambios']);
    }

    public function test_producto_que_desaparece_se_marca_fuera_de_fuente_y_no_se_borra(): void
    {
        $this->loader()->load(self::sinteticas(20));

        // Corrida siguiente: vienen 18 de 20 (>= 50 % de la previa, la guarda no se activa).
        $sin = array_slice(self::sinteticas(20), 0, 18);
        $r = $this->loader()->load($sin);

        $this->assertSame(2, $r['fuera_de_fuente']);
        $this->assertFalse($r['guarda_activada']);
        $this->assertSame(20, LafayetteProducto::query()->count(), 'nunca se borra');
        $this->assertSame(2, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
        $this->assertNotNull(LafayetteProducto::query()->where('lafayette_id', 'SYN0000019')->value('fuera_de_fuente_at'));
    }

    public function test_producto_que_reaparece_limpia_la_marca(): void
    {
        $this->loader()->load(self::sinteticas(20));
        $this->loader()->load(array_slice(self::sinteticas(20), 0, 18));
        $this->assertSame(2, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());

        $r = $this->loader()->load(self::sinteticas(20));

        $this->assertSame(2, $r['actualizados']);
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_guarda_extraccion_vacia_no_marca_nada(): void
    {
        $this->loader()->load(self::sinteticas(20));

        $r = $this->loader()->load([]);

        $this->assertTrue($r['guarda_activada']);
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_guarda_por_debajo_de_la_fraccion_minima_de_la_corrida_previa_no_marca_nada(): void
    {
        $this->loader()->load(self::sinteticas(20));
        LafayetteSyncRun::query()->create([
            'extraidos' => 20, 'creados' => 20, 'actualizados' => 0, 'errores' => 0, 'duracion_ms' => 1,
            'status' => LafayetteSyncRun::STATUS_SUCCESS, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        // 5 de 20 = 25 % < 50 %.
        $r = $this->loader()->load(array_slice(self::sinteticas(20), 0, 5));

        $this->assertTrue($r['guarda_activada']);
        $this->assertSame(0, $r['fuera_de_fuente']);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
    }

    public function test_no_toca_catalogo_stock_ni_bodegas(): void
    {
        $central = Warehouse::query()->where('name', 'Bodega Central')->firstOrFail();
        $zonaLibre = Warehouse::query()->where('name', 'Bodega Zona Libre')->firstOrFail();
        $product = CatalogProduct::query()->create(['reference' => 'REF-ATLANTICERP-1', 'name' => 'Producto de AtlanticERP', 'price_full' => 10.0, 'is_active' => true]);
        ProductWarehouseStock::query()->create(['catalog_product_id' => $product->id, 'warehouse_id' => $central->id, 'quantity' => 5]);

        $snapshot = fn (): array => [
            DB::table((new CatalogProduct())->getTable())->count(),
            DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson(),
            DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson(),
            DB::table((new Warehouse())->getTable())->orderBy('id')->get()->toJson(),
        ];
        $antes = $snapshot();

        $this->loader()->load(self::muestra());
        $this->loader()->load(self::sinteticas(300));

        $this->assertSame($antes, $snapshot(), 'el pipeline no puede tocar catalog_products, product_warehouse_stock ni warehouses');
        $this->assertSame(0, ProductWarehouseStock::query()->where('warehouse_id', $zonaLibre->id)->count());
    }

    public function test_carga_masiva_de_2000_filas_e_idempotencia(): void
    {
        $r = $this->loader()->load(self::sinteticas(2000));
        $this->assertSame(2000, $r['insertados']);

        $r2 = $this->loader()->load(self::sinteticas(2000));
        $this->assertSame(0, $r2['insertados'] + $r2['actualizados']);
        $this->assertSame(2000, $r2['sin_cambios']);
        $this->assertSame(2000, LafayetteProducto::query()->count());
    }

    public function test_indice_unico_impide_duplicados_a_nivel_de_base_de_datos(): void
    {
        $this->loader()->load(self::sinteticas(3));

        $this->expectException(\Illuminate\Database\QueryException::class);
        LafayetteProducto::query()->create(['lafayette_id' => 'SYN0000001', 'disponible' => 1]);
    }

    // ── fuente_actualizado_at no entra en la comparación del upsert ──────────────────────────────────────────

    /** @return list<MappedLafayetteProducto> */
    private static function conMarca(array $lote, \Carbon\Carbon $marca, ?string $idCambiado = null, int $disponible = 10, float $precio = 1.5): array
    {
        return array_map(static fn (MappedLafayetteProducto $p): MappedLafayetteProducto => new MappedLafayetteProducto(
            $p->lafayetteId, $p->referencia, $p->descripcion, $p->marca, $p->categoria, $p->urlImagen,
            $p->lafayetteId === $idCambiado ? $disponible : $p->disponible, $p->lafayetteId === $idCambiado ? $precio : $p->precio5, $p->precio6, $marca,
        ), $lote);
    }

    public function test_si_solo_cambia_la_marca_de_cache_de_la_fuente_no_se_escribe_nada(): void
    {
        $lote = self::sinteticas(20);
        $primera = \Carbon\Carbon::parse('2026-10-01 11:58:35');
        $this->loader()->load(self::conMarca($lote, $primera));
        $table = (new LafayetteProducto())->getTable();
        DB::table($table)->update(['updated_at' => '2000-01-01 00:00:00+00']);

        // Lafayette reconstruyó su caché: TODAS las filas traen una marca nueva, ningún dato de negocio cambió.
        $r = $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-01 11:58:43')));

        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(0, $r['insertados']);
        $this->assertSame(20, $r['sin_cambios']);
        $this->assertSame(0, DB::table($table)->where('updated_at', '<>', '2000-01-01 00:00:00+00')->count(), 'updated_at intacto: no se escribió ninguna fila');
        $this->assertTrue(LafayetteProducto::query()->firstOrFail()->fuente_actualizado_at->equalTo($primera), 'fuente_actualizado_at queda como estaba');
    }

    public function test_si_cambia_un_dato_de_negocio_se_actualiza_incluida_la_marca_de_cache(): void
    {
        $lote = self::sinteticas(20);
        $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-01 11:58:35')));
        $nueva = \Carbon\Carbon::parse('2026-10-01 11:58:43');

        foreach ([['disponible', 99, 1.5], ['precio_5', 10, 7.25]] as [$campo, $disp, $precio]) {
            $r = $this->loader()->load(self::conMarca($lote, $nueva, 'SYN0000005', $disp, $precio));

            $this->assertSame(1, $r['actualizados'], "cambio de {$campo}");
            $this->assertSame(19, $r['sin_cambios']);
            $row = LafayetteProducto::query()->where('lafayette_id', 'SYN0000005')->firstOrFail();
            $this->assertTrue($row->fuente_actualizado_at->equalTo($nueva), 'toma el valor nuevo cuando la fila sí cambió');
            // Las filas que no cambiaron conservan su marca vieja.
            $this->assertTrue(LafayetteProducto::query()->where('lafayette_id', 'SYN0000006')->firstOrFail()->fuente_actualizado_at->equalTo(\Carbon\Carbon::parse('2026-10-01 11:58:35')));
        }
    }

    public function test_una_fila_fuera_de_fuente_que_reaparece_se_actualiza_aunque_solo_cambie_la_marca(): void
    {
        $lote = self::sinteticas(20);
        $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-01 11:58:35')));
        $this->loader()->load(self::conMarca(array_slice($lote, 0, 18), \Carbon\Carbon::parse('2026-10-01 11:58:35')));
        $this->assertSame(2, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
        $nueva = \Carbon\Carbon::parse('2026-10-02 12:00:00');

        $r = $this->loader()->load(self::conMarca($lote, $nueva));

        $this->assertSame(2, $r['actualizados']);
        $this->assertSame(18, $r['sin_cambios']);
        $this->assertSame(0, LafayetteProducto::query()->whereNotNull('fuera_de_fuente_at')->count());
        $this->assertTrue(LafayetteProducto::query()->where('lafayette_id', 'SYN0000019')->firstOrFail()->fuente_actualizado_at->equalTo($nueva));
    }

    public function test_una_segunda_corrida_identica_con_marcas_nuevas_escribe_cero_filas(): void
    {
        $lote = self::sinteticas(20);
        $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-01 11:58:35')));
        $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-01 11:58:43'), 'SYN0000001', 11)); // un cambio real
        DB::table((new LafayetteProducto())->getTable())->update(['updated_at' => '2000-01-01 00:00:00+00']);

        $r = $this->loader()->load(self::conMarca($lote, \Carbon\Carbon::parse('2026-10-02 12:00:00'), 'SYN0000001', 11));

        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(20, $r['sin_cambios']);
    }
}
